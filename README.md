# 📦 Web-Based General Services Office (GSO) Borrowing and Inventory Management System

## 📖 Overview

The Web-Based GSO Borrowing and Inventory Management System is a centralized application developed to automate and streamline the borrowing and inventory processes of the General Services Office. The system replaces manual operations with a digital platform that enhances efficiency, accuracy, and transparency in managing organizational resources.

## 🎯 Objectives

* Automate borrowing request submission and approval processes
* Provide real-time tracking of inventory and borrowed items
* Improve monitoring and reporting of resource usage
* Implement notification mechanisms for system updates
* Ensure secure, role-based access control

## ✨ Key Features

* User Authentication and Authorization (Admin and Borrower roles)
* Borrowing Request Management (submit, approve, reject, release)
* Inventory Management System (resource tracking and stock monitoring)
* Return Processing Module
* Notification System (real-time alerts and updates)
* Low Stock Alert Mechanism
* Activity Logs and Reports Generation

## 🛠️ Technologies Used

* Frontend: HTML, CSS, JavaScript
* Backend: PHP
* Database: MySQL
* Email Service: PHPMailer
* Server Environment: XAMPP (Apache)

## ⚙️ System Requirements

* XAMPP (Apache & MySQL)
* Modern web browser (Chrome, Edge, Firefox)

## 🚀 Installation Guide

### 1. Clone the Repository

git clone https://github.com/luwisssssss/gso-system.git

### 2. Move the Project Folder

Place the project inside:
C:\xampp\htdocs\

### 3. Start the Server

* Open XAMPP Control Panel
* Start Apache and MySQL

### 4. Setup Database

* Open http://localhost/phpmyadmin
* Create a database (e.g., gso_system)
* Import the provided .sql file

### 5. Configure Environment Variables

* Copy `.env.example` and rename it to `.env`

Update:
DB_HOST=localhost
DB_NAME=gso_system
DB_USER=root
DB_PASS=

MAIL_HOST=smtp.gmail.com
MAIL_USER=[your-email@gmail.com](mailto:your-email@gmail.com)
MAIL_PASS=your-app-password
MAIL_PORT=587
MAIL_SECURE=tls

### 6. Run the Application

http://localhost/GSO_WebSystem

## 📁 Project Structure

/admin
/borrower
/auth
/config
/includes
/pages
/assets
/scripts
/docs

## 🔒 Security Implementation

* Uses `.env` for sensitive credentials
* `.gitignore` protects confidential files
* Secure session handling implemented
* No passwords exposed in repository

## ⚠️ Notes

* Ensure Apache and MySQL are running
* Do not upload `.env`
* Use app password for email

## 👨‍💻 Developers




## 📌 Academic Context

This system is developed as part of the BSIT program.

## 📷 Screenshots

(Add screenshots here)

## 📜 License

For academic purposes only.
