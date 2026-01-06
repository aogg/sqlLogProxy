import java.sql.*;
import java.util.HashMap;
import java.util.Map;

public class TestJdbc {
    public static void main(String[] args) {
        Map<String, String> params = parseArgs(args);

        String host = params.get("host");
        String port = params.get("port");
        String database = params.get("database");
        String user = params.get("user");
        String password = params.get("password");

        if (host == null || port == null || database == null || user == null || password == null) {
            System.err.println("Usage: java TestJdbc --host <host> --port <port> --database <database> --user <user> --password <password>");
            System.exit(1);
        }

        String url = String.format("jdbc:mysql://%s:%s/%s?useSSL=false&serverTimezone=UTC", host, port, database);

        try {
            Class.forName("com.mysql.cj.jdbc.Driver");
            System.out.println("Attempting to connect to: " + url);
            System.out.println("User: " + user);

            // 简化连接参数
            String testUrl = url + "?useSSL=false&serverTimezone=UTC&allowPublicKeyRetrieval=true&useServerPrepStmts=false";

            try (Connection conn = DriverManager.getConnection(testUrl, user, password)) {
                System.out.println("Connection established successfully!");
                System.out.println("Connection info: " + conn.getMetaData().getDatabaseProductName() + " " + conn.getMetaData().getDatabaseProductVersion());

                // 首先执行一个简单的查询测试代理是否工作
                try (Statement stmt = conn.createStatement()) {
                    System.out.println("Testing with a simple SELECT query...");
                    try (ResultSet rs = stmt.executeQuery("SELECT 1 as test_column")) {
                        System.out.println("Simple query executed successfully!");
                        while (rs.next()) {
                            System.out.println("Result: " + rs.getString(1));
                        }
                    }

                    System.out.println("Now executing SHOW TABLES...");
                    try (ResultSet rs = stmt.executeQuery("SHOW TABLES")) {
                        while (rs.next()) {
                            System.out.println(rs.getString(1));
                        }
                    }
                }
            }
        } catch (ClassNotFoundException e) {
            System.err.println("MySQL JDBC driver not found. Make sure mysql-connector-java.jar is in classpath.");
            System.exit(1);
        } catch (SQLException e) {
            System.err.println("Database connection failed: " + e.getMessage());
            System.err.println("SQL State: " + e.getSQLState());
            System.err.println("Error Code: " + e.getErrorCode());
            e.printStackTrace();
            System.exit(1);
        }
    }

    private static Map<String, String> parseArgs(String[] args) {
        Map<String, String> params = new HashMap<>();
        for (int i = 0; i < args.length; i += 2) {
            if (i + 1 < args.length && args[i].startsWith("--")) {
                String key = args[i].substring(2);
                String value = args[i + 1];
                params.put(key, value);
            }
        }
        return params;
    }
}
