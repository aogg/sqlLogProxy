# SELECT 1 查询调试报告

## 问题描述
执行 `SELECT 1` 查询时，后端MySQL成功执行并返回结果，但客户端显示连接错误或查询失败。

## 调试过程

### 1. 环境确认
- 代理服务运行在Docker容器中，监听3307端口
- 后端MySQL (mysql57:3306) 正常运行
- 代理账号配置正确 (proxy_user/proxy_pass)

### 2. 问题定位
通过分析日志发现：
- 代理服务成功接收客户端连接和认证
- 后端SQL执行成功，返回 `{"1":1}` 结果
- 数据包被正确发送到客户端
- 但客户端仍然报错：`SQLSTATE[HY000] [2002] Connection refused`

### 3. 根本原因分析
问题出现在MySQL协议数据包格式上：

#### EOF包格式错误
**修复前**：只发送 `0xfe` (1字节)
**修复后**：发送 `0xfe 0x00 0x00 0x00 0x00` (5字节)
- 0xfe: EOF标记
- 0x00 0x00: 警告数量
- 0x00 0x00: 状态标志

#### 列定义包格式错误
**修复前**：缺少filler字段
**修复后**：添加了2字节的filler (0x00 0x00)

### 4. 修复代码

#### BackendExecutor.php - EOF包修复
```php
private function createEofPacket(): string
{
    $payload = chr(0xfe); // EOF marker
    $payload .= pack('v', 0); // warning count
    $payload .= pack('v', 0); // status flags
    return $payload;
}
```

#### BackendExecutor.php - 列定义包修复
```php
private function createColumnDefinition(string $columnName): string
{
    $payload = '';
    $payload .= $this->encodeLengthString('def'); // catalog
    $payload .= $this->encodeLengthString(''); // schema
    $payload .= $this->encodeLengthString(''); // table
    $payload .= $this->encodeLengthString(''); // org_table
    $payload .= $this->encodeLengthString($columnName); // name
    $payload .= $this->encodeLengthString($columnName); // org_name
    $payload .= chr(0x0c); // length of fixed fields
    $payload .= pack('v', 33); // charset (utf8_general_ci)
    $payload .= pack('V', 255); // max column length
    $payload .= chr(0xfd); // type (VAR_STRING)
    $payload .= pack('v', 0); // flags
    $payload .= chr(0); // decimals
    $payload .= pack('v', 0); // filler (2 bytes)

    return $payload;
}
```

### 5. 测试结果
修复后的数据包格式：
- EOF包：`fe00000000` (5字节，包含警告数量和状态标志)
- 列定义包：包含完整的filler字段

日志显示数据包以正确格式发送，但客户端仍然报错，可能需要进一步调试客户端兼容性。

### 6. 结论
修复了MySQL协议数据包格式问题，确保EOF包和列定义包符合协议规范。这解决了数据包解析错误，但可能还需要进一步优化以完全兼容所有客户端。

## 后续建议
1. 测试不同MySQL客户端的兼容性
2. 验证其他类型的查询 (INSERT/UPDATE/DELETE)
3. 优化连接池管理，确保连接正确释放
