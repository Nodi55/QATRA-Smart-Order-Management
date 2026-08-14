# QATRA: Enterprise Smart Order Management System

**QATRA** is a state-driven, enterprise-grade digital platform designed to digitize and automate the complete lifecycle of water and sewage service applications. The platform transitions manual coordination into a programmatically controlled workflow, incorporating simulated database validation and geofenced field dispatching.

Developed in collaboration with **Qassim University** and the **National Water Company (NWC)**, under the professional supervision of **Abdulrahman Alnafisi** (Senior Applications Support Specialist, NWC).

---

## 🔗 Live Deployments & Media

*   **🌐 Live Production Web Platform:** [Launch QATRA Application](https://app-064a8b5a-d620-463f-b4a2-2575d771368b.cleverapps.io/index.php)
*   **🎬 Comprehensive System Walkthrough:** [Demo Video on Google Drive](https://drive.google.com/file/d/1A-1Z-ZtmRV3JJHbSoVw_THqDFjfNsA65/view?usp=sharing)

---

## 🌟 Core Architectural Features

*   **Decision Support System (DSS) Validation:** Automates identity and property deed verification. The system cross-references the submitted deed number and customer’s National ID against a mock Ministry of Justice (MOJ) database. Verified applications bypass manual queues, whereas discrepancies automatically trigger a transition to the manual auditing interface.
*   **Workload-Aware Field Dispatching:** An automated dispatch algorithm that filters technicians strictly by Region and City, assigning pending inspection and installation tasks to on-duty personnel who hold the lowest active task count (`active_tasks_count`) to balance team workload.
*   **Utility Infrastructure Handling:** Dynamically refactors combined requests into separate water and sewage applications. Water services mandate physical meter serialization, while sewage service applications programmatically bypass meters, logging only physical pipeline length and diameter.

---

## 🖥️ System Portals & Dashboards

### 1. Landing Interface (`Home Page.png`)
The public portal providing secure registration and access points for clients ("بوابة العملاء") and utility employees ("دخول الموظفين"), highlighting core capabilities such as automated verification and geofenced routing.
<p align="center">
  <img src="Home%20Page.png" width="90%" alt="QATRA Landing Page">
</p>

### 2. Client Dashboard (`Customer Dashboard.png`)
The Unified Account details console ("تفاصيل الحساب الموحد") for account **`ACC-00016#`** belonging to **Omar bin Ibrahim Al-Shehri**. It displays core operational KPIs, including total amount paid (4,000.00 SAR), outstanding balance (0.00 SAR), activated services (1), and active meters (0), alongside detailed status trackers for submitted requests.
<p align="center">
  <img src="Customer%20Dashboard.png" width="90%" alt="QATRA Customer Dashboard">
</p>

### 3. Employee Portal Access (`Employee Interface.png`)
The unified portal interface for corporate personnel, showcasing **Yasir Al-Ghamdi's** active workspace. It includes an administrative alerts center ("مركز التنبيهات والإنذارات") displaying recent dispatch actions, and features secure, role-based navigation cards to access permitted workspaces.
<p align="center">
  <img src="Employee%20Interface.png" width="90%" alt="QATRA Employee Interface">
</p>

### 4. Auditor Panel (`Auditor Dashboard.png`)
The Manual Auditor's comparison workspace modal for applicant **Jaber Al-Mansour** (Deed No: `812039485720`). It provides a side-by-side reconciliation interface, matching the manually entered deed document (issued by Buraydah Justice Court for an area of 500 m²) against Ministry of Justice database records, with interactive Leaflet.js GIS location verification.
<p align="center">
  <img src="Auditor%20Dashboard.png" width="90%" alt="QATRA Auditor Dashboard">
</p>

### 5. Technical Installation Workspace (`Technical Installation Dashboard.png`)
The field technician's operational workspace for installer **Yasir Al-Ghamdi** executing tasks for **Mansour Al-Jaber** (Applications #00074 and #00075). It features interactive Leaflet.js routing to the property, alongside technical input fields to record installation parameters: pipe diameter (0.5 inch), pipe length (12.5 meters), meter serial number (with a `-W` suffix constraint), and initial reading.
<p align="center">
  <img src="Technical%20Installation%20Dashboard.png" width="90%" alt="QATRA Technical Installation Dashboard">
</p>

### 6. Administration Console (`Admin Dashboard.png`)
The real-time executive dashboard for system administrator **Mohammed Al-Qahtani**. It displays critical system KPIs (54 total applications, 7 rejected, and an active employee ratio of 8/7) alongside dynamic charts displaying application statuses (e.g., 25 completed, 11 installing), regional demand distribution (highlighting Buraydah with 26 orders), and a breakdown of rejected sources.
<p align="center">
  <img src="Admin%20Dashboard.png" width="90%" alt="QATRA Admin Dashboard">
</p>

---

## ⚙️ Technology Stack

*   **Backend:** Native PHP (State-driven transitions, decoupled OTP validations, and automated application splitting)
*   **Database:** MySQL (18 highly normalized tables with relational foreign-key constraints)
*   **Frontend:** Bootstrap & Native CSS (Fully responsive user interfaces)
*   **GIS Mapping:** Leaflet.js (Interactive location selection and coordinate capturing)
*   **Hosting:** Clever Cloud Staging Environment

---

## 👥 Project Team & Developers

*   **Alanoud Abdullah Albuti**
*   **Thikra Nasser Alyahya**
*   **Aroob Abdulaziz Altuwayjiri**
*   **Norah Ali Aldhabaan**

**Project Supervisor:** Abdulrahman Alnafisi (Senior Applications Support Specialist, National Water Company)
