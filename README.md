# 📸 Moodle Quiz Face Warning & Suspicious Behavior Capture

## 📌 Title
**Moodle Proctoring Add-on – Face Warnings & Suspicious Behavior Evidence**

## 📖 Description
This module extends the **Moodle Quiz Proctoring plugin** with an **automated suspicious behavior detector**.  
It monitors the test-taker’s webcam in real time, detects abnormal activity (no face, looking away, excessive movement), and captures annotated snapshots as **evidence warnings**.  

Captured warnings are **stored locally in the browser**, displayed in an **in-page gallery**, and **synchronized with the server** via `uploadwarning.php`. The server organizes evidence images per quiz attempt and provides a **web viewer dashboard** for teachers/admins to review, download, or delete suspicious data.

---

## ✨ Features
- **Face Detection & Monitoring**  
  - Uses [Face-API.js](https://github.com/justadudewhohacks/face-api.js) models for lightweight real-time detection.  
  - Fallback detection if models are missing.

- **Suspicious Event Detection**
  - ❌ No Face Detected – > 11 consecutive frames.  
  - 🔄 Excessive Movement – bounding box shift > 57 pixels.  
  - ↔ Face Turned Away – yaw angle > 25° for > 8 frames.  

- **Evidence Capture**
  - Automatic snapshot with **reason header + timestamp footer**.  
  - Local gallery modal with **delete/clear** controls.  
  - Saves images in `localStorage` for session persistence.  

- **Server Upload**
  - Uploads PNG snapshots to `uploadwarning.php` with metadata (`cmid`, `reportid`, `type`).  
  - Retry mechanism (3 attempts).  
  - JSON log (`upload_log.json`) maintained with `user_id` + filenames.  

- **Teacher/Admin Dashboard**
  - Accessible at:  
    ```
    /mod/quiz/accessrule/proctoring/uploadwarning.php
    ```
  - Lists suspicious images per quiz/user.  
  - Supports `?view=<filename>&quiz=<cmid>` to view specific image.  
  - `DELETE` endpoint clears warnings + logs for a quiz.  



---

## 🛠️ Installation

1. **Place files** inside your Moodle plugin folder:
   ```
   mod/quiz/accessrule/proctoring/
     ├── face_warning.js
     ├── uploadwarning.php
     └── models/    # Face-API model weights
   ```

2. **Load JS in attempt pages** (already included in `rule.php`):
   ```php
   $page->requires->js(new moodle_url('/mod/quiz/accessrule/proctoring/face_warning.js'));
   $page->requires->js_call_amd('quizaccess_proctoring/proctoring', 'setup', [$record, $modelurl]);
   ```

3. **Ensure permissions**  
   - PHP must be able to write to Moodledata `uploads/warnings/`.  
   - Enable camera access in the browser.

4. **Clear caches** and restart quiz.

---

## ▶️ Usage

- Start a **quiz attempt with proctoring enabled**.  
- Move away, turn your face, or leave the frame → warnings triggered.  
- Teachers: open View Reports in the quiz proctoring dashboard and use the View Warning option to review Warning images.
---

## 📂 Project Structure
```
proctoring/
 ├── face_warning.js         # Frontend detection, UI, upload logic
 ├── uploadwarning.php       # Backend API for storing/reviewing warnings
 ├── models/                 # Face-API model weights (TinyFace, Landmarks)
 └── uploads/
      └── warnings/
          ├── quiz_<cmid>/warning_<type>_<reportid>_<timestamp>.png
          └── upload_log.json
```


---

## 📊 Example Workflow
1. Student looks away for >8 frames.  
2. `face_warning.js` triggers **“Face Turned Away” warning**.  
3. Snapshot taken → stored in gallery + `localStorage`.  
4. Auto-upload to server (`uploadwarning.php`).  
5. Teacher opens dashboard → sees **timestamped warning image** with student ID.

---

## 🔗 Repository Metadata
- **Repo Name:** `moodle-quiz-face-warning`
- **Primary Language:** JavaScript / PHP
- **Dependencies:** Face-API.js, Moodle Proctoring Plugin
- **Maintainer:** Mostafa Gamal – Faculty of Computers and Information, Ain Shams University
- **Status:** Stable

📌 Acknowledgement  
This project was developed at the **Network and Information Technology Center, Ain Shams University**,  
by **Software Engineer Mostafa Gamal**, to serve the university's e-learning and exam monitoring needs.

