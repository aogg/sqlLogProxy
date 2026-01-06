# JDBC 测试工具

运行 PowerShell 脚本测试 JDBC 连接并显示所有表：

```powershell
./build_and_run.ps1 -HostName 127.0.0.1 -Port 3306 -Database testdb -User root -Password ""
```

脚本会自动下载 MySQL JDBC 驱动、编译源码并运行测试。

## 手动下载 JDBC 驱动

如果网络受限无法自动下载，请手动下载：

1. 访问：https://repo1.maven.org/maven2/com/mysql/mysql-connector-j/8.0.33/mysql-connector-j-8.0.33.jar
2. 将文件保存为 `mysql-connector-java.jar`
3. 放入 `test/jdbc/lib/` 目录

或者使用代理下载：
```bash
curl -x http://localhost_alpine:8899 -o mysql-connector-java.jar "https://repo1.maven.org/maven2/com/mysql/mysql-connector-j/8.0.33/mysql-connector-j-8.0.33.jar"
```


# powershell运行注意
- 核心：用 -ExecutionPolicy Bypass 临时允许运行当前脚本
powershell -ExecutionPolicy Bypass -File "D:\code\www\my\github\sqlLogProxy\test\jdbc\build_and_run.ps1"