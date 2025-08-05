🔑 Key Features
🔐 Authentication System
Admin and user login

Role-based access control (admin/user)

Secure session management

Default admin credentials:

Username: admin

Password: admin123

📁 File Management
Folder navigation similar to Windows Explorer

Breadcrumb navigation for easy path tracking

Switch between List and Grid view modes

Double-click to open files or folders

Right-click context menu with options:

Open

Share

Download

Copy

Cut

Rename

Properties

Delete

🔍 Search & Sorting
Global search box to find files/folders

Sort by name, type, size, or date

Real-time search with input debouncing

🎨 UI/UX
Toggle between Light and Dark themes

Fully responsive design

Windows Explorer-style interface

Sidebar with Quick Access shortcuts

File icons based on file extension

🖼️ Media Preview
Gallery mode for images (.jpg, .png, .gif, etc.)

Built-in audio player for .mp3, .wav

HTML5 video player for .mp4, .webm

Modal preview for supported media files

👥 User Management (Admin Only)
Admin dashboard to manage users

Set file permissions by user role

Hide certain file extensions from non-admin users

Auto-hide sensitive files (.php, .htaccess, config files)

⚙️ File Operations
Create new folders

Rename files and folders

Delete files and folders

View file properties in a dialog

Perform Copy and Cut operations

Download files

⌨️ Keyboard Shortcuts
Shortcut	Action
Ctrl + C	Copy
Ctrl + X	Cut
Ctrl + F	Focus search
Delete	Delete file
F2	Rename
Enter	Open file/folder

🚀 Setup Instructions
📦 Database Setup
Create the database:

sql
Salin
Edit
CREATE DATABASE filemanager;
Update the database config in the code:

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
Ensure the main directories have write permissions

Create required folders if not present:

uploads/

documents/

images/

🔐 Default Login
Username: admin

Password: admin123

🔒 Security Features
Auto-hide sensitive files for normal users (e.g., .php, .env, .htaccess)

Role-based access restrictions

Protection against SQL injection using prepared statements

XSS protection using htmlspecialchars()

File type restrictions for non-admin users

📱 Mobile Responsive
Sidebar auto-hides on smaller screens

Touch-friendly interface for tablets and phones

Responsive grid layout for flexible content display

