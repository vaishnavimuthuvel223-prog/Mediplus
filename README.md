# 🏥 Dynamic Hospital Appointment and Emergency Priority System

A web-based hospital management system designed to make **appointment scheduling, token management, queue handling, and emergency response more dynamic and efficient**.

Instead of relying only on fixed appointment times or token numbers, the system considers different real-world factors such as **emergency level, waiting time, age category, VIP priority, late arrival, doctor availability, and specialization** to support dynamic queue management.

---

## 🎯 Project Objective

Traditional hospital queues may not always handle changing patient situations effectively.

Our system aims to provide a **dynamic, transparent, and responsive hospital workflow** by helping patients, doctors, nurses, and administrators manage appointments and patient flow through dedicated dashboards.

---

## 🚀 Key Features

### 👤 Patient Module

* Patient registration and login
* Doctor/department-based appointment booking
* Unique token generation
* Department-wise queue management
* View current token and people ahead
* Estimated waiting time
* Appointment cancellation
* Emergency alert with live location sharing

### 👨‍⚕️ Doctor Module

* Secure doctor sign-in with face-based authentication
* View patient details
* View priority scores and queue positions
* Manage consultation flow
* Priority Token mode
* Age Priority mode
* Normal Flow mode
* Open/close token availability
* Add prescription and medical notes
* Handle lab requests

### 🚨 Emergency Management

* Emergency alert system
* Patient live location sharing
* Emergency monitoring for hospital staff
* Priority handling for emergency cases

### 👩‍⚕️ Nurse Module

* Patient monitoring
* Emergency monitoring
* Patient-care updates
* Lab-related updates

### 🛠️ Admin Module

* Monitor appointments and queues
* Manage doctors and availability
* Generate walk-in tokens
* Handle cancellations
* Manage patient status and queue updates
* Monitor hospital workflow

### ⭐ VIP Module

* Dedicated VIP login
* VIP priority handling within the queue management workflow

### 🧪 Lab & Pharmacy

* Lab test requests
* Lab report processing
* Prescription information
* Pharmacy processing support

---

## 🧠 Dynamic Priority System

The queue is designed to consider multiple factors rather than following only the original token order.

Priority factors include:

* 🚨 Emergency level
* ⭐ VIP status
* 👴 Age category
* ⏳ Waiting time
* 🕐 Appointment time
* 📍 Arrival / late arrival
* 👨‍⚕️ Doctor availability
* 🏥 Doctor specialization
* 📋 Patient status

The priority can be recalculated when the patient's situation changes.

---

## 👥 User Roles

| Role         | Main Responsibilities                                  |
| ------------ | ------------------------------------------------------ |
| 👤 Patient   | Appointments, tokens, queue tracking, emergency alerts |
| 👨‍⚕️ Doctor | Patient consultation, queue control, prescriptions     |
| 👩‍⚕️ Nurse  | Patient and emergency monitoring                       |
| 🛠️ Admin    | Hospital and queue management                          |
| ⭐ VIP        | Dedicated priority workflow                            |

---

## 💻 Technologies Used

* **Frontend:** HTML, CSS, JavaScript
* **Backend:** PHP
* **Database:** MySQL
* **Local Server:** XAMPP
* **Development Environment:** Visual Studio Code

---

## 📂 Project Structure

```text
Dynamic-Hospital-Appointment-System/
│
├── admin/
├── assets/
│   ├── css/
│   └── js/
├── auth/
├── config/
├── database/
├── doctor/
├── emergency/
├── includes/
├── management/
├── nurse/
├── patient/
├── queue/
│
├── index.php
├── CHANGES_TOKEN_TRAVEL.md
├── README.md
└── .gitignore
```

---

## ⚙️ How to Run the Project

### 1. Install XAMPP

Download and install XAMPP.

### 2. Start Services

Open XAMPP Control Panel and start:

```text
Apache
MySQL
```

### 3. Copy the Project

Extract the project into:

```text
C:\xampp\htdocs\
```

For example:

```text
C:\xampp\htdocs\Dynamic-Hospital-Appointment-System
```

### 4. Create the Database

Open:

```text
http://localhost/phpmyadmin
```

Create a database named:

```text
mediplus
```

Import the SQL file from the project's `database` folder.

### 5. Configure Database Connection

Copy:

```text
config/db.example.php
```

to:

```text
config/db.php
```

Update the database details according to your local XAMPP configuration.

### 6. Run the Application

Open:

```text
http://localhost/Dynamic-Hospital-Appointment-System/
```

---

## 🔐 Security Note

The repository does not include the local `config/db.php` file.

Use `config/db.example.php` as a template and create your local database configuration separately.

Do not commit:

* Database passwords
* API keys
* Environment variables
* Private credentials
* Real patient information

---

## 🌟 Project Highlights

This project combines:

**Appointment Management + Dynamic Queue Management + Emergency Response + Live Location + Role-Based Dashboards**

The main idea is to move beyond a simple appointment booking system and create a workflow that can **respond to changing hospital situations**.

---

## 👩‍💻 Team

**Vaishnavi Muthuvel**
**Varshini Chellamuthu**
**Shanthiyashri R B**

---

## 🔖 Keywords

`PHP` `MySQL` `JavaScript` `HTML` `CSS` `XAMPP` `Healthcare` `HealthTech` `Hospital Management` `Queue Management` `Emergency Management` `Web Development`
