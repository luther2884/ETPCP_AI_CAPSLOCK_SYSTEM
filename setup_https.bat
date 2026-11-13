@echo off
echo Setting up HTTPS for TechWorld PWA...
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% == 0 (
    echo Running as Administrator - Good!
) else (
    echo Please run this script as Administrator
    pause
    exit /b 1
)

REM Navigate to XAMPP SSL directory
cd /d "C:\xampp\apache\conf\ssl.crt\"

echo Checking for existing certificates...
if exist "server.crt" (
    echo Certificate already exists. Skipping generation.
) else (
    echo Generating SSL certificates...
    
    REM Generate private key
    echo Generating private key...
    openssl genrsa -out server.key 2048
    
    REM Generate certificate signing request
    echo Generating certificate signing request...
    openssl req -new -key server.key -out server.csr -subj "/C=US/ST=State/L=City/O=Organization/OU=OrgUnit/CN=localhost"
    
    REM Generate self-signed certificate
    echo Generating self-signed certificate...
    openssl x509 -req -days 365 -in server.csr -signkey server.key -out server.crt
    
    echo Certificate generation complete!
)

echo.
echo Updating Apache configuration...

REM Backup original httpd-ssl.conf
if not exist "httpd-ssl.conf.backup" (
    copy "C:\xampp\apache\conf\extra\httpd-ssl.conf" "C:\xampp\apache\conf\extra\httpd-ssl.conf.backup"
    echo Backup created: httpd-ssl.conf.backup
)

REM Update httpd-ssl.conf with TechWorld paths
echo Updating SSL configuration...
powershell -Command "(Get-Content 'C:\xampp\apache\conf\extra\httpd-ssl.conf') -replace 'DocumentRoot \"C:/xampp/htdocs\"', 'DocumentRoot \"C:/xampp/htdocs/TECHWRLD4.5\"' | Set-Content 'C:\xampp\apache\conf\extra\httpd-ssl.conf'"

echo.
echo Configuration updated!
echo.
echo Next steps:
echo 1. Open XAMPP Control Panel
echo 2. Stop Apache if it's running
echo 3. Start Apache again
echo 4. Open your browser and go to: https://localhost/TECHWRLD4.5/
echo 5. Accept the security warning for the self-signed certificate
echo.
echo Your TechWorld PWA should now be accessible via HTTPS!
echo.
pause

