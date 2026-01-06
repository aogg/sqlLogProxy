param(
    [string]$HostName = "",
    [string]$Port = "",
    [string]$Database = "",
    [string]$User = "",
    [string]$Password = ""
)

# 检查 JDK 是否可用
if (-not (Get-Command javac -ErrorAction SilentlyContinue)) {
    Write-Error "JDK not found. Please install JDK and ensure javac is in PATH."
    exit 1
}

if (-not (Get-Command java -ErrorAction SilentlyContinue)) {
    Write-Error "Java runtime not found. Please install JDK and ensure java is in PATH."
    exit 1
}

# 创建必要目录
$libDir = "lib"
$binDir = "bin"
New-Item -ItemType Directory -Force -Path $libDir | Out-Null
New-Item -ItemType Directory -Force -Path $binDir | Out-Null

# 下载 MySQL JDBC 驱动（如果不存在）
$driverPath = "$libDir/mysql-connector-java.jar"
if (-not (Test-Path $driverPath)) {
    Write-Host "Downloading MySQL JDBC driver..."
    try {
        Invoke-WebRequest -Uri "https://repo1.maven.org/maven2/com/mysql/mysql-connector-j/8.0.33/mysql-connector-j-8.0.33.jar" -OutFile $driverPath
        Write-Host "Driver downloaded successfully."
    } catch {
        Write-Error "Failed to download MySQL JDBC driver. Please manually place mysql-connector-java.jar in $libDir directory."
        Write-Error "Download URL: https://repo1.maven.org/maven2/com/mysql/mysql-connector-j/8.0.33/mysql-connector-j-8.0.33.jar"
        Write-Error "Or use proxy: curl -x http://localhost_alpine:8899 -o mysql-connector-java.jar <URL>"
        exit 1
    }
}

# 编译 Java 源码
Write-Host "Compiling Java source..."
javac -cp "$libDir/*" -d $binDir TestJdbc.java
if ($LASTEXITCODE -ne 0) {
    Write-Error "Compilation failed."
    exit 1
}

# 运行程序
Write-Host "Running JDBC test..."
$classPath = "$binDir;$libDir/*"
java -cp $classPath TestJdbc --host $HostName --port $Port --database $Database --user $User --password $Password
