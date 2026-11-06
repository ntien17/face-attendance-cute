<<<<<<< HEAD
# 🐱 Hệ thống điểm danh nhận diện khuôn mặt lớp học học đường

Dự án demo dùng:

- PHP: giao diện, webcam, xử lý điểm danh, quản trị lớp.
- Python: AI nhận diện khuôn mặt bằng `face_recognition`.
- JSON: lưu danh sách học sinh và lịch sử điểm danh, không dùng MySQL.

## Cấu trúc

```text
face-attendance-cute/
├── index.php
├── admin_login.php
├── admin_dashboard.php
├── export_excel.php
├── requirements.txt
├── database/
│   ├── students.json
│   └── attendance.json
├── dataset/
│   └── .gitkeep
├── uploads/
│   └── .gitkeep
└── core/
    └── ai_engine.py
```

## Tài khoản quản trị mẫu

```text
Tài khoản: 
Mật khẩu: 
```

## Cài đặt nhanh trên Windows với XAMPP

1. Copy thư mục dự án vào:

```text
C:\xampp\htdocs\face-attendance-cute
```

2. Tạo môi trường ảo Python trong thư mục dự án:

```bash
cd C:\xampp\htdocs\face-attendance-cute
python -m venv .venv
.venv\Scripts\activate
pip install --upgrade pip
pip install -r requirements.txt

https://github.com/z-mahmud22/Dlib_Windows_Python3.x?utm_source=copilot.com
 tìm file : dlib‑20.0.99‑cp314‑cp314‑win_amd64.whl
 Tải file .whl về máy (ví dụ thư mục Downloads).

Mở PowerShell và di chuyển đến thư mục chứa file:

powershell
cd C:\Users\Asus\Downloads
Cài đặt bằng pip:

powershell
pip install "C:\Users\Asus\Downloads\dlib-20.0.99-cp314-cp314-win_amd64.whl" 
Sau khi cài thành công, tiếp tục:

powershell
pip install face-recognition
```

3. Mở `index.php`, sửa biến `$python_exe` nếu cần:

```php
$python_exe = __DIR__ . "\\.venv\\Scripts\\python.exe";
```

4. Khởi động Apache trong XAMPP.

5. Truy cập:

```text
http://localhost/face-attendance-cute/
```

## Luồng sử dụng

1. Vào `admin_login.php`.
2. Đăng nhập bằng tài khoản mẫu.
3. Thêm học sinh ở `admin_dashboard.php`, chụp ảnh mẫu bằng webcam.
4. Quay lại `index.php`, bấm nhận diện điểm danh.
5. Tải file Excel ở nút xuất báo cáo.

## Lưu ý kỹ thuật

- Webcam thường yêu cầu chạy qua `localhost` hoặc HTTPS.
- Thư mục `database/`, `dataset/`, `uploads/` cần có quyền ghi.
- `face_recognition` phụ thuộc `dlib`; trên Windows có thể cần Visual Studio Build Tools hoặc bản Python tương thích.
- Dự án dùng cho demo học tập. Với môi trường thật, cần cơ chế bảo mật tốt hơn, phân quyền, mã hóa dữ liệu sinh trắc học và sự đồng ý của người được thu thập ảnh.
=======
# face-attendance-cute
phần mềm điểm danh, xử lí ảnh
>>>>>>> ddd33c1249e6c4d358d939539819151560caed3f
