<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

---

# Reltroner HRM (Human Resource Manager)

**Reltroner HRM** is a Laravel 12-based human resource management application, built with the traditional stack of Laravel + Blade + Tailwind + Vite. This project is part of the digital infrastructure of *Reltroner Studio*.

## ✨ Key Features

* Employee CRUD
* Department & Role Management
* Task Management (CRUD + Status: Complete/Pending)
* Attendance Tracking
* Payroll Processing
* Leave Request Handling
* Form Validation & Flash Messages
* Dashboard Statistics
* Soft Deletes + Eloquent Relationships

## 📆 Tech Stack

* Laravel 12
* Blade Templating Engine
* Tailwind CSS + Mazer Template
* MySQL / MariaDB
* Laravel Breeze (Auth)
* Flatpickr, DataTables

## 🧠 Design Philosophy

Reltroner HRM is not just a practice project, but a technical pillar of the world-building studio. It integrates principles like:

* Meritocratic systems
* SDI Structure (Sentient Development Index)
* Clean and intuitive UI

## 🚿 Bug & Issue Notes

### 1. Sidebar Active Highlight Not Dynamic

* Sidebar `active` class does not automatically follow the current page.
* **Solution:** use `request()->routeIs()` helper on sidebar-item.

### 2. Date Fields (Birth Date & Hire Date) Empty on Edit

* Although the data is stored, the date input fields do not display it.
* **Solution:** ensure `birth_date` & `hire_date` are cast to `date` in the model and formatted with `format('Y-m-d')`.

## 🚀 Local Installation

```bash
git clone https://github.com/reltroner/reltroner-hr-app.git
cd reltroner-hr-app
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run dev
php artisan serve
```

## 📁 Module Structure

```
- app/Models
  - Employee.php
  - Task.php
  - Department.php
  - Role.php
  - Payroll.php
  - Presence.php
  - LeaveRequest.php
- app/Http/Controllers
  - EmployeeController.php
  - TaskController.php
  - DepartmentController.php
  - RoleController.php
  - PayrollController.php
  - PresenceController.php
  - LeaveRequestController.php
```

### ➕ Admin Leave Request Form
![Admin Leave Request Form](public/images/admin-ui.png)

### 📊 User Leave Request Form
![User Leave Request Form](public/images/user-ui.png)

## 🔐 Access & Authentication

* Laravel Breeze is enabled
* All routes are protected with `auth` middleware (except login/register)  
* Role-based authorization demo (Admin vs Employee)

## 🔐 Access for Recruiters / HRD – Demo Accounts

For testing and demo purposes, you can log in as:

- **Admin:**  
  user: `admin@example.com`  
  password: `password`

- **Employee:**  
  user: `developer@example.com`  
  password: `password`

---

🌍 **Live Demo:** [hrm.reltroner.com](https://hrm.reltroner.com)  
💻 **Source:** [github.com/Reltroner/reltroner-hr-app.git](https://github.com/Reltroner/reltroner-hr-app.git)

---

## 👨‍💻 Developer

* **Rei Reltroner** – Founder & Developer
* [Reltroner Studio](https://reltroner.com) – Digital Worldbuilding & Product Ecosystem

---

## License

This project is built with Laravel and follows the [MIT License](https://opensource.org/licenses/MIT).
