@echo off
echo Setting up TechWorld PWA for mobile access...
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

echo Getting your IP address...
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4 Address"') do (
    set IP=%%a
    goto :found
)
:found
set IP=%IP: =%
echo Your IP address is: %IP%

echo.
echo Updating Apache configuration for network access...

REM Backup original httpd.conf
if not exist "httpd.conf.backup" (
    copy "C:\xampp\apache\conf\httpd.conf" "C:\xampp\apache\conf\httpd.conf.backup"
    echo Backup created: httpd.conf.backup
)

REM Update httpd.conf for network access
echo Updating httpd.conf...
powershell -Command "(Get-Content 'C:\xampp\apache\conf\httpd.conf') -replace 'Listen 80', 'Listen 0.0.0.0:80' | Set-Content 'C:\xampp\apache\conf\httpd.conf'"
powershell -Command "(Get-Content 'C:\xampp\apache\conf\httpd.conf') -replace 'Listen 443', 'Listen 0.0.0.0:443' | Set-Content 'C:\xampp\apache\conf\httpd.conf'"
powershell -Command "(Get-Content 'C:\xampp\apache\conf\httpd.conf') -replace 'Require local', 'Require all granted' | Set-Content 'C:\xampp\apache\conf\httpd.conf'"

REM Update httpd-ssl.conf
echo Updating SSL configuration...
powershell -Command "(Get-Content 'C:\xampp\apache\conf\extra\httpd-ssl.conf') -replace 'ServerName localhost:443', 'ServerName %IP%:443' | Set-Content 'C:\xampp\apache\conf\extra\httpd-ssl.conf'"

echo.
echo Generating SSL certificate for your IP address...
cd /d "C:\xampp\apache\conf\ssl.crt\"

REM Generate certificate for the IP address
openssl genrsa -out server.key 2048
openssl req -new -key server.key -out server.csr -subj "/C=US/ST=State/L=City/O=Organization/OU=OrgUnit/CN=%IP%"
openssl x509 -req -days 365 -in server.csr -signkey server.key -out server.crt

echo.
echo Configuration complete!
echo.
echo Next steps:
echo 1. Open XAMPP Control Panel
echo 2. Stop Apache if it's running
echo 3. Start Apache again
echo 4. Make sure your mobile device is on the same WiFi network
echo 5. Open your mobile browser and go to: https://%IP%/TECHWRLD4.5/
echo 6. Accept the security warning
echo 7. Install the PWA from the download page
echo.
echo Alternative method using ngrok:
echo 1. Download ngrok from https://ngrok.com/download
echo 2. Run: ngrok http 80
echo 3. Use the ngrok URL on your mobile device
echo.
pause




