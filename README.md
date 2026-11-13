# ETPCP-AI: E-Commerce Technology Product Configuration Platform with AI-Based Recommendation System for TechWorld Computer Store

ETPCP-AI is a B2C e-commerce website for TechWorld Computer Store. Customers can browse, configure, and order computer products, with AI-based product recommendations. It also gives the store's managers, owner, and delivery personnel tools to manage products, orders, inventory, deliveries, and reports.

---

## Group Members & Roles
* **Ravelo, Raphael Luther L.** – Team Leader / Front-End Developer / Technical Writer
* **Pawig, Miguelito Chester** – Lead Developer / System Analyst / Back-End Developer
* **Cerda, Rhuel A.** – Database Designer / Back-End Developer / QA Tester
* **Go, Niño Angelo M.** – System Analyst / Back-End Developer / QA Tester
* **Pelayo, Marc Adrian A.** – Front-End Developer / Technical Writer / Researcher

---

## Tech Stack
* **Frontend:** HTML, CSS, JavaScript
* **Backend:** PHP 8.1+
* **Database:** MySQL (via XAMPP / phpMyAdmin)
* **AI:** Google Gemini API (AI product builder and recommendations)
* **Other Services:** PHPMailer (email OTP), Xendit (payments, test mode), Google Sign-In, Endroid QR Code, PhpSpreadsheet
* **Tools & Version Control:** Git, GitHub, VS Code, XAMPP, Composer

---

## System Requirements & Prerequisites
Before you begin, make sure you have the following installed:
* [XAMPP](https://www.apachefriends.org/) with **PHP 8.1 or higher**, Apache, and MySQL
* [Git](https://git-scm.com/)
* A web browser (Google Chrome recommended)

In `C:\xampp\php\php.ini`, make sure these lines are enabled (no `;` at the start), then restart Apache:
```ini
extension=gd
extension=zip
extension=mbstring
```

---

## Installation & Setup Instructions

### 1. Clone the Repository
Clone the project into your XAMPP `htdocs` folder:
```bash
cd C:\xampp\htdocs
git clone https://github.com/luther2884/ETPCP_AI_CAPSLOCK_SYSTEM.git TECHWRLD
cd TECHWRLD
```

### 2. Install Dependencies
Composer is already included in the project. Run:
```bash
C:\xampp\php\php.exe Composer.phar install
```

### 3. Create the `.env` File
```bash
copy .env.example .env
```
Open `.env` and fill in your own keys if you want to use these features (optional):
* `GEMINI_API_KEY` – for the AI builder and recommendations ([get a free key](https://aistudio.google.com/apikey))
* `XENDIT_SECRET_KEY` – for online payments (test mode)
* `GOOGLE_CLIENT_ID` – for "Continue with Google" (leave blank to hide the button)

The system still runs without these keys. Only the related features will not work.

---

## Database Setup & Configuration

1. **Start XAMPP** and click **Start** on **Apache** and **MySQL**.
2. **Create a new database:**
   * Open phpMyAdmin at `http://localhost/phpmyadmin`.
   * Create a new database named `techworld_ecommerce`.
3. **Import the database:**
   * Select the `techworld_ecommerce` database and go to the **Import** tab.
   * Choose the file `database/techworld_ecommerce.sql` and click **Import**.
4. **Database connection:**
   * The default settings in `config/database.php` already match XAMPP:
     ```
     DB_HOST = localhost
     DB_NAME = techworld_ecommerce
     DB_USER = root
     DB_PASS = (blank)
     ```
   * If your MySQL has a password, add these lines to `.env`:
     ```env
     DB_HOST=localhost
     DB_NAME=techworld_ecommerce
     DB_USER=root
     DB_PASS=your_mysql_password
     ```

---

## Running the Application

1. Make sure **Apache** and **MySQL** are running in XAMPP.
2. Open your browser and go to:

| Page | URL |
|------|-----|
| Customer Store | `http://localhost/TECHWRLD/` |
| Customer Login | `http://localhost/TECHWRLD/public/guest/login.php` |
| Admin (Manager) Login | `http://localhost/TECHWRLD/public/authenticated/admin/login.php` |
| Delivery Personnel Login | `http://localhost/TECHWRLD/public/authenticated/courier/login.php` |
| Super Admin Login | `http://localhost/TECHWRLD/public/authenticated/super-admin/login.php` |

---

## Default Login Credentials

| Role | Email | Password |
|------|-------|----------|
| Super Admin (Owner) | `superadmin@local` | `superadmin123` |
| Admin (Manager) | `admin@techworld.test` | `Demo@1234` |
| Delivery Personnel | `delivery@techworld.test` | `Demo@1234` |
| Customer | `customer@techworld.test` | `Demo@1234` |

### Login Verification Code (OTP)
After entering the email and password, the system asks for a **6-digit code** sent by email.

* **With email set up:** Go to **Super Admin → Email Settings** and enter a Gmail address and [App Password](https://myaccount.google.com/apppasswords). The code will then be sent by email.
* **Without email (local testing):** The code is saved in the PHP session file. Open PowerShell and run:
  ```powershell
  Select-String -Path C:\xampp\tmp\sess_* -Pattern 's:6:"(\d{6})"' | ForEach-Object { $_.Matches[0].Groups[1].Value }
  ```
  Use the **last** code shown. Codes expire after 5 minutes.

---

## Notes
* These are demo accounts for testing only. Change the Super Admin password in `config/functions.php` before using this system for real.
* All customer data in the included database is sample data.
