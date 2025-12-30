# MySQL TLS 代理服务

这是一个基于 Hyperf 框架的 MySQL 协议代理服务，支持 TLS/SSL 连接和代理层认证。客户端使用代理内配置的账号连接代理，代理使用写死的后端 MySQL 账号执行 SQL 并返回结果。

## 功能特性

- ✅ **TLS/SSL 支持**: 支持客户端通过 `sslmode=require` 或 `sslmode=verify-*` 连接
- ✅ **代理认证**: 客户端使用代理配置的账号进行认证，不直接使用后端 MySQL 账号
- ✅ **统一后端账号**: 所有 SQL 都通过配置的固定 MySQL 账号执行
- ✅ **连接池**: 自动管理后端 MySQL 连接池，提高性能
- ✅ **SQL 日志**: 完整的 SQL 执行日志记录
- ✅ **协议兼容**: 支持基本的 MySQL 协议，包括查询、预处理语句等

## 系统要求

- **PHP**: >= 8.1
- **Swoole**: >= 5.0，支持 SSL (编译时需开启 `--enable-openssl`)
- **扩展**: pdo_mysql, pcntl, json, openssl
- **环境**: Linux/Mac/Windows (Docker)

## 快速开始

### 1. 环境检查

```bash
# 检查 Swoole SSL 支持和其他环境要求
php test/check_swoole_ssl.php
```

### 2. 配置 SSL 证书

```bash
# 创建证书目录
mkdir -p runtime/certs

# 生成自签名证书（生产环境请使用正式证书）
openssl req -x509 -newkey rsa:4096 -keyout runtime/certs/server.key -out runtime/certs/server.crt -days 365 -nodes -subj '/CN=localhost'
```

### 3. 配置代理账号和后端 MySQL

编辑 `config/autoload/proxy.php`：

```php
'proxy_accounts' => [
    [
        'username' => 'proxy_user',
        'password' => 'proxy_pass',
        'database' => '', // 空表示不限制数据库
    ],
],

'backend_mysql' => [
    'host' => 'mysql57',
    'port' => 3306,
    'username' => 'root',
    'password' => 'root',
    'database' => '',
],
```


### 5. 测试连接

```bash
# 测试 SSL 连接和代理认证
php test/ssl_test.php

# 或使用 mysql 客户端
mysql --ssl-mode=REQUIRED -h 127.0.0.1 -P 3317 -u proxy_user -p proxy_pass
```

## 配置说明

### 代理账号配置

在 `config/autoload/proxy.php` 中配置允许连接代理的账号：

```php
'proxy_accounts' => [
    [
        'username' => 'app_user',
        'password' => 'secure_password',
        'database' => 'app_db', // 可选，限制可访问的数据库
    ],
    [
        'username' => 'admin',
        'password' => 'admin_pass',
        'database' => '', // 空字符串表示可访问任何数据库
    ],
],
```

### 后端 MySQL 配置

配置代理用于连接真实 MySQL 的账号：

```php
'backend_mysql' => [
    'host' => 'mysql-server',
    'port' => 3306,
    'username' => 'service_account',
    'password' => 'service_password',
    'database' => '', // 默认数据库，可为空
    'charset' => 'utf8mb4',
    'tls' => false, // 是否对后端使用 TLS
],
```

### SSL/TLS 配置

```php
'tls' => [
    'server_cert' => BASE_PATH . '/runtime/certs/server.crt',
    'server_key' => BASE_PATH . '/runtime/certs/server.key',
    'ca_cert' => null, // 用于客户端证书验证
    'require_client_cert' => false,
],
```

## 架构说明

```
客户端 (mysql --ssl-mode=REQUIRED)
    ↓ TLS 连接
MySQL 代理 (端口 3317)
├── 代理认证 (验证 proxy_accounts)
├── SQL 执行 (使用 backend_mysql 账号)
└── 结果返回 (MySQL 协议)
    ↓
真实 MySQL 服务器
```

## 故障排除

### 连接失败

1. **检查服务状态**:
   ```bash
   php bin/hyperf.php status
   ```

2. **查看日志**:
   ```bash
   tail -f runtime/logs/hyperf.log
   tail -f runtime/logs/connection.log
   ```

3. **测试证书**:
   ```bash
   openssl s_client -connect 127.0.0.1:3317 -servername localhost
   ```

### 常见问题

- **SSL 连接失败**: 检查证书文件是否存在且可读
- **认证失败**: 确认代理账号配置正确
- **后端连接失败**: 检查后端 MySQL 服务器可访问性
- **连接池耗尽**: 调整 `pool.size` 配置

## 开发说明

项目结构:
- `App/Proxy/Protocol/` - MySQL 协议处理
- `App/Proxy/Auth/` - 代理认证逻辑
- `App/Proxy/Executor/` - 后端执行器
- `App/Proxy/Pool/` - 连接池管理
- `config/autoload/proxy.php` - 代理配置
- `test/` - 测试脚本
