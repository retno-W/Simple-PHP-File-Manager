# 📂 FileManager Web App

A web-based file manager with a Windows Explorer-like interface, complete with authentication, media previews, user roles, and file operations.

---

## 🔑 Key Features

### 🔐 Authentication System
- Admin and user login  
- Role-based access control (admin/user)  
- Secure session management  
- **Default admin credentials:**  
  - Username: `admin`  
  - Password: `admin123`  

### 📁 File Management
- Folder navigation similar to Windows Explorer  
- Breadcrumb navigation  
- List and Grid view modes  
- Double-click to open files/folders  
- Right-click context menu with:
  - Open  
  - Share  
  - Download  
  - Copy  
  - Cut  
  - Rename  
  - Properties  
  - Delete  

### 🔍 Search & Sorting
- Global search across directories  
- Sort by name, type, size, and date  
- Real-time search with input debouncing  

### 🎨 UI/UX
- Light/Dark theme toggle  
- Fully responsive design  
- Explorer-style layout  
- Sidebar with Quick Access shortcuts  
- Dynamic file icons by extension  

### 🖼️ Media Preview
- Gallery mode for images (`.jpg`, `.png`, `.gif`, etc.)  
- Audio player for `.mp3`, `.wav`  
- HTML5 video player for `.mp4`, `.webm`  
- Modal preview for media files  

### 👥 User Management (Admin)
- Admin dashboard to manage users  
- Role-based file permissions  
- Hidden extensions for non-admin users  
- Auto-hide sensitive files (`.php`, `.htaccess`, etc.)  

### ⚙️ File Operations
- Create new folders  
- Rename files/folders  
- Delete files/folders  
- File properties dialog  
- Copy/Cut operations  
- Download files  

### ⌨️ Keyboard Shortcuts

| Shortcut  | Action         |
|-----------|----------------|
| Ctrl + C  | Copy           |
| Ctrl + X  | Cut            |
| Ctrl + F  | Focus search   |
| Delete    | Delete file    |
| F2        | Rename         |
| Enter     | Open file      |

---

## 🚀 Setup Instructions

### 📦 Database Setup

1. Create the database:
   ```sql
   CREATE DATABASE filemanager;
Update database configuration in the code:

php
Salin
Edit
$db_config = [
    'host' => 'localhost',
    'dbname' => 'filemanager',
    'username' => 'your_username',
    'password' => 'your_password'
];
🛠 File Permissions
Ensure the following directories have write permissions:

uploads/

documents/

images/

🔐 Default Login
Username: admin

Password: admin123

🔒 Security Features
Auto-hide sensitive files for non-admin users (e.g., .php, config files)

Role-based access control

SQL injection protection using prepared statements

XSS protection using htmlspecialchars()

File type restrictions for non-admin users

📱 Mobile Responsive
Sidebar automatically hides on smaller screens

Touch-friendly interface

Responsive grid layout for optimal viewing on all devices
