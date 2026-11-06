#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
core/ai_engine.py
Bộ não AI nhận diện khuôn mặt bằng thư viện face_recognition.

Cách PHP gọi:
    python core/ai_engine.py uploads/scan_xxx.jpg

Kết quả in ra stdout là JSON UTF-8:
    {"status": "success", "student_id": "HS001", "name": "...", "role": "...", "distance": 0.42}

Cài đặt gợi ý:
    pip install -r requirements.txt

Lưu ý:
- face_recognition phụ thuộc dlib. Trên Windows, dlib có thể cần Visual Studio Build Tools
  hoặc cài wheel phù hợp với phiên bản Python.
- Ảnh mẫu nên rõ mặt, đủ sáng, mỗi ảnh một khuôn mặt chính.
"""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

import face_recognition
import numpy as np

try:
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
except Exception:
    pass

ROOT_DIR = Path(__file__).resolve().parents[1]
DATASET_DIR = ROOT_DIR / "dataset"
STUDENTS_FILE = ROOT_DIR / "database" / "students.json"
TOLERANCE = 0.5
SUPPORTED_EXTENSIONS = {".jpg", ".jpeg", ".png"}


def print_json(payload: Dict[str, Any]) -> None:
    """In JSON UTF-8 để PHP json_decode không lỗi tiếng Việt."""
    print(json.dumps(payload, ensure_ascii=False))


def load_students() -> Dict[str, Dict[str, Any]]:
    """Đọc danh sách học sinh từ database/students.json, trả về dict theo mã học sinh."""
    if not STUDENTS_FILE.exists():
        return {}

    try:
        raw = STUDENTS_FILE.read_text(encoding="utf-8")
        data = json.loads(raw) if raw.strip() else []
    except Exception:
        return {}

    if not isinstance(data, list):
        return {}

    students: Dict[str, Dict[str, Any]] = {}
    for item in data:
        if not isinstance(item, dict):
            continue
        student_id = str(item.get("ma_hoc_sinh", "")).strip()
        if student_id:
            students[student_id] = item
    return students


def extract_first_face_encoding(image_path: Path) -> Tuple[Optional[np.ndarray], int]:
    """
    Trích xuất encoding khuôn mặt đầu tiên trong ảnh.
    Trả về (encoding, số_khuôn_mặt_tìm_thấy).
    """
    image = face_recognition.load_image_file(str(image_path))
    face_locations = face_recognition.face_locations(image, model="hog")

    if not face_locations:
        return None, 0

    encodings = face_recognition.face_encodings(image, face_locations)
    if not encodings:
        return None, len(face_locations)

    return encodings[0], len(face_locations)


def get_student_id_from_image_path(image_path: Path) -> str:
    """
    Quy đổi tên ảnh mẫu về mã học sinh.
    Ví dụ:
    - HS001.jpg   -> HS001
    - HS001_1.jpg -> HS001
    - HS001_2.jpg -> HS001
    """
    return image_path.stem.strip().split("_")[0]


def load_known_faces(students: Dict[str, Dict[str, Any]]) -> Tuple[List[np.ndarray], List[str]]:
    """Quét dataset/ và mã hóa toàn bộ ảnh mẫu hợp lệ."""
    known_encodings: List[np.ndarray] = []
    known_ids: List[str] = []

    if not DATASET_DIR.exists():
        return known_encodings, known_ids

    for image_path in sorted(DATASET_DIR.iterdir()):
        if not image_path.is_file():
            continue

        if image_path.suffix.lower() not in SUPPORTED_EXTENSIONS:
            continue

        student_id = get_student_id_from_image_path(image_path)
        if student_id not in students:
            # Chỉ dùng ảnh mẫu có thông tin trong students.json.
            # HS001_1.jpg vẫn thuộc học sinh HS001.
            continue

        try:
            encoding, face_count = extract_first_face_encoding(image_path)
        except Exception:
            continue

        if encoding is None or face_count == 0:
            continue

        known_encodings.append(encoding)
        known_ids.append(student_id)

    return known_encodings, known_ids


def recognize(input_image: Path) -> Dict[str, Any]:
    """Nhận diện ảnh đầu vào và trả về payload JSON."""
    if not input_image.exists():
        return {
            "status": "error",
            "message": "Không tìm thấy ảnh đầu vào."
        }

    students = load_students()
    if not students:
        return {
            "status": "no_students",
            "message": "Chưa có học sinh trong database/students.json."
        }

    known_encodings, known_ids = load_known_faces(students)
    if not known_encodings:
        return {
            "status": "empty_dataset",
            "message": "Chưa có ảnh mẫu hợp lệ trong dataset/."
        }

    try:
        input_encoding, face_count = extract_first_face_encoding(input_image)
    except Exception as exc:
        return {
            "status": "error",
            "message": "Không đọc được ảnh đầu vào.",
            "detail": str(exc)
        }

    if input_encoding is None or face_count == 0:
        return {
            "status": "unknown",
            "message": "Không tìm thấy khuôn mặt trong ảnh."
        }

    distances = face_recognition.face_distance(known_encodings, input_encoding)
    if len(distances) == 0:
        return {
            "status": "failed",
            "message": "Không có dữ liệu khuôn mặt để so sánh."
        }

    best_index = int(np.argmin(distances))
    best_distance = float(distances[best_index])

    if best_distance > TOLERANCE:
        return {
            "status": "failed",
            "message": "Khuôn mặt không khớp với dữ liệu mẫu.",
            "distance": round(best_distance, 4),
            "tolerance": TOLERANCE
        }

    student_id = known_ids[best_index]
    student = students.get(student_id, {})

    return {
        "status": "success",
        "student_id": student_id,
        "name": student.get("ho_ten", ""),
        "role": student.get("to_chuc_vu", ""),
        "distance": round(best_distance, 4),
        "tolerance": TOLERANCE,
        "face_count": face_count
    }


def main() -> None:
    if len(sys.argv) < 2:
        print_json({
            "status": "error",
            "message": "Thiếu tham số đường dẫn ảnh đầu vào."
        })
        return

    input_path = Path(sys.argv[1]).resolve()
    result = recognize(input_path)
    print_json(result)


if __name__ == "__main__":
    main()
