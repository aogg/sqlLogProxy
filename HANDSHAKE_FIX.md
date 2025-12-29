# MySQL 握手错误修复说明

## 问题描述
使用 MySQL Connector/J 8.2.0 连接时出现 `[08S01][1043] Bad handshake.` 错误。

## 修复内容

### 1. 更新 CAPABILITIES 标志
- **修改文件**: `app/Protocol/MySql/Handshake.php`
- **旧值**: `0x000880df`
- **新值**: `0x00aff7df`
- **原因**: MySQL Connector/J 8.2.0 期望服务器支持更多能力标志，包括：
  - `CLIENT_PROTOCOL_41` (0x00000200)
  - `CLIENT_MULTI_STATEMENTS` (0x00010000)
  - `CLIENT_MULTI_RESULTS` (0x00020000)
  - `CLIENT_PLUGIN_AUTH` (0x00080000)
  - `CLIENT_CONNECT_ATTRS` (0x00100000)
  - 等等

### 2. 增加详细日志
- **修改文件**: `app/Service/ProxyService.php`
- **新增内容**:
  - 握手包详细信息日志（包括十六进制和 ASCII 表示）
  - 客户端数据详细日志
  - 客户端认证响应解析日志（包括 capabilities 详细信息）
  - 连接关闭详情日志
  - 新增 `toAscii()` 辅助方法用于日志输出

### 3. 更新服务器版本号
- **修改文件**: `app/Protocol/MySql/Handshake.php`
- **旧值**: `5.7.0-sqlLogProxy`
- **新值**: `5.7.44-sqlLogProxy`
- **原因**: 匹配实际的 MySQL 版本，减少客户端疑虑


### 2. 查看日志
```bash
# 查看连接日志
tail -f runtime/logs/connection.log

# 查看默认日志
tail -f runtime/logs/hyperf.log

# 查看SQL日志
tail -f runtime/logs/sql.log
```

### 3. 使用 MySQL Connector/J 8.2.0 测试连接
确保使用以下连接字符串：
```
jdbc:mysql://localhost:3317/your_database?useSSL=false
```

## 预期结果

### 成功连接时，日志应该显示：
1. **握手包发送**:
   ```
   [info] 握手包发送结果 {"client_id":"1","handshake_length":xx,...}
   ```

2. **收到客户端认证响应**:
   ```
   [info] 检测到客户端认证响应，准备连接目标MySQL {...}
   [info] 解析客户端认证信息成功 {"username":"root","database":"test",...}
   ```

3. **成功连接到目标MySQL**:
   ```
   [info] MySQL连接建立成功 {"host":"mysql57.common-all","port":3306,...}
   ```

### 如果仍然失败，检查：
1. 客户端的 capabilities 是否与服务器匹配
2. 认证插件名称是否正确（`mysql_native_password`）
3. 字符集设置是否正确
4. 目标 MySQL 服务器是否正常运行

## 关键改进点

### CAPABILITIES 标志详解
```
0x00aff7df 的组成部分：
- 0x00000001: CLIENT_LONG_PASSWORD
- 0x00000002: CLIENT_FOUND_ROWS
- 0x00000004: CLIENT_LONG_FLAG
- 0x00000008: CLIENT_CONNECT_WITH_DB
- 0x00000010: CLIENT_NO_SCHEMA
- 0x00000020: CLIENT_COMPRESS
- 0x00000040: CLIENT_ODBC
- 0x00000080: CLIENT_LOCAL_FILES
- 0x00000100: CLIENT_IGNORE_SPACE
- 0x00000200: CLIENT_PROTOCOL_41 (重要)
- 0x00000400: CLIENT_INTERACTIVE
- 0x00000800: CLIENT_SSL
- 0x00001000: CLIENT_IGNORE_SIGPIPE
- 0x00002000: CLIENT_TRANSACTIONS
- 0x00004000: CLIENT_RESERVED
- 0x00008000: CLIENT_SECURE_CONNECTION
- 0x00010000: CLIENT_MULTI_STATEMENTS (重要)
- 0x00020000: CLIENT_MULTI_RESULTS (重要)
- 0x00040000: CLIENT_PS_MULTI_RESULTS
- 0x00080000: CLIENT_PLUGIN_AUTH
- 0x00100000: CLIENT_CONNECT_ATTRS
- 0x00200000: CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA
```

## 注意事项

1. **CAPABILITIES 必须同步更新**:
   - `Handshake::CAPABILITIES`
   - `Auth::CAPABILITIES`

2. **日志级别**:
   - 使用 `debug` 级别记录详细信息
   - 使用 `info` 级别记录关键事件
   - 使用 `warning` 记录异常情况
   - 使用 `error` 记录错误

3. **性能考虑**:
   - 生产环境可以适当降低日志级别
   - 使用日志轮转避免日志文件过大
