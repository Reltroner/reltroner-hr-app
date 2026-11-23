<p align="center">
  <a href="https://laravel.com" target="_blank">
    <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo">
  </a>
</p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

---

# 🌐 Reltroner HRM  
**Laravel 12 Human Resource Manager**

Reltroner HRM is a modern human resource management system built with **Laravel 12**, **Blade**, **Tailwind**, and **Vite** — part of the digital infrastructure of **Reltroner Studio**.  
This README also includes a full **deployment guide** for Hostinger + Vercel, documenting every issue and solution.

---

# ✨ Features

- Employee CRUD  
- Departments & Roles  
- Task Management (CRUD + status tracking)  
- Attendance Monitoring  
- Payroll System  
- Leave Requests  
- Dashboard statistics  
- Soft deletes & Eloquent relationships  
- Blade UI with Tailwind + Mazer  
- Authentication via Laravel Breeze  

---

# 📦 Tech Stack

- **Laravel 12**
- **Blade Templating**
- **Tailwind CSS**
- **MySQL / MariaDB**
- **Laravel Breeze (Auth)**
- **Vite**
- **DataTables, Flatpickr**

---

# 🧠 Design Philosophy

Built as a real-world HR workflow engine incorporating:

- Meritocratic structures  
- SDI (Sentient Development Index)  
- Clean UI for productivity  
- Stable backend with modular architecture  

---

# 📚 Module Structure

```

app/Models
Employee.php
Task.php
Department.php
Role.php
Payroll.php
Presence.php
LeaveRequest.php

app/Http/Controllers
EmployeeController.php
TaskController.php
DepartmentController.php
RoleController.php
PayrollController.php
PresenceController.php
LeaveRequestController.php

```

---

# 🔐 Demo Accounts

| Role | Email | Password |
|------|--------|----------|
| Admin | admin@example.com | password |
| Employee | developer@example.com | password |

---

# 🌍 Live Deployment  
[https://hrm.reltroner.com/](https://hrm.reltroner.com/)

---

# 📥 Local Installation

```
git clone https://github.com/Reltroner/reltroner-hr-app.git
cd reltroner-hr-app

composer install
cp .env.example .env
php artisan key:generate

php artisan migrate --seed
npm install
npm run dev

php artisan serve
```

---

# 🚀 Reltroner HRM Deployment Guide

**Laravel 12 • Hostinger (Backend) + Vercel (Frontend)**
**DNS • SSH • Git Auto Deployment**

This documents every real bug encountered during deployment — with fixes.

---

# 🧭 Overview

### Deployment Goals

✔ `reltroner.com` served by **Vercel**
✔ `hrm.reltroner.com` served by **Hostinger** (Laravel)
✔ Auto-deploy from GitHub → Hostinger
✔ Proper production `.env`
✔ Clean DNS with no conflict

---

# 🏗 Architecture

```
reltroner.com (Root Domain)
├── Hosted on Vercel
└── Using Vercel Nameservers
      ns1.vercel-dns.com
      ns2.vercel-dns.com

hrm.reltroner.com (Subdomain)
├── Hosted on Hostinger Shared Hosting
├── Laravel 12 Production Build
└── DNS Record (on Vercel DNS)
      A hrm → 145.79.28.61
```

---

# 🐞 Common Deployment Issues & Fixes

## 1️⃣ Error: “Project Directory Not Empty”

**Fix**

```bash
rm -rf public_html/hrm/*
```

---

## 2️⃣ Laravel 500 Error

**Fix**

```bash
cp .env.example .env
php artisan key:generate
php artisan config:clear
php artisan cache:clear
```

---

## 3️⃣ SQLite Missing Error

```
database.sqlite does not exist
```

**Fix — switch to MySQL in `.env`:**

```
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u235364453_hrm
DB_USERNAME=u235364453_hrmuser
DB_PASSWORD=your_mysql_password
```

---

## 4️⃣ MySQL Access Denied

```
Access denied for user 'root'
```

**Fix**

```bash
php artisan config:clear
php artisan cache:clear
```

---

## 5️⃣ Root domain loads Hostinger default page

**Cause:** Hostinger A records still active.

**Fix:** Switch to Vercel nameservers:

```
ns1.vercel-dns.com
ns2.vercel-dns.com
```

---

## 6️⃣ DNS conflict (Vercel + Hostinger)

**Correct Vercel DNS**

```
A @     76.76.21.21
A hrm   145.79.28.61
CNAME www cname.vercel-dns.com
```

---

## 7️⃣ Hostinger Git Deployment Not Updating on Push

**Cause:** HTTPS repo does not allow auto-deploy.
**Fix:** Use SSH Deploy Key.

**Generate Key**

```bash
ssh-keygen -t ed25519 -f ~/.ssh/hostinger_deploy
cat ~/.ssh/hostinger_deploy.pub
```

Add this public key to:

**GitHub → Repo → Settings → Deploy Keys**

Set repo URL in Hostinger:

```
git@github.com:Reltroner/reltroner-hr-app.git
```

Enable **Auto Deployment**.

---

# 🌐 DNS Setup

## Vercel Nameservers (Required)

```
ns1.vercel-dns.com
ns2.vercel-dns.com
```

## Vercel DNS Records

```
A @       76.76.21.21
A hrm     145.79.28.61
CNAME www cname.vercel-dns.com
```

---

# ⚙ Laravel Production Setup (Hostinger)

Run these after Git deployment:

```bash
cp .env.example .env
php artisan key:generate
php artisan config:clear
php artisan cache:clear
php artisan storage:link
php artisan migrate --force
```

Fix permissions:

```bash
chmod -R 775 storage bootstrap/cache
```

---

# 🔐 SSH Deployment Setup

### Generate key (Hostinger SSH terminal):

```bash
ssh-keygen -t ed25519
```

### Add to GitHub Deploy Keys

**Repo → Settings → Deploy Keys → Add Key**

### Hostinger Git Configuration

```
Repository: git@github.com:Reltroner/reltroner-hr-app.git
Branch: master
Install Path: hrm
Auto Deployment: ON
```

Every `git push` now updates Hostinger automatically.

---

# 🎉 Final Working State

✔ `reltroner.com` → Vercel frontend
✔ `hrm.reltroner.com` → Laravel backend on Hostinger
✔ Auto-deploy via SSH
✔ No 500 errors
✔ MySQL connected
✔ Proper DNS routing
✔ Fully production-ready

---

# 🔥 Key Takeaways

* Multi-host deployment (Vercel + Hostinger) is 100% possible
* Laravel production requires proper `.env`, permissions, and cache clearing
* SSH Deploy Keys are the best approach for full CI/CD on Hostinger
* DNS misconfiguration is the #1 cause of deployment failures
* Debugging builds real DevOps intuition

---

# 👨‍💻 Developer

**Rei Reltroner**
Founder • Developer — Reltroner Studio
[https://reltroner.com](https://reltroner.com)

---

# 📜 License

This project is licensed under the **MIT License**.

