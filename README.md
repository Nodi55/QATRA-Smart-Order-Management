# QATRA: Enterprise Smart Order Management System

**QATRA** is a state-driven, enterprise-grade digital platform designed to digitize and automate the complete lifecycle of water and sewage service applications. The platform transitions manual coordination into a programmatically controlled workflow, incorporating simulated external database matching and geofenced field dispatching.

Developed in collaboration with **Qassim University** and the **National Water Company (NWC)**, under the professional supervision of **Abdulrahman Alnafisi** (Senior Applications Support Specialist, NWC).

---

## 🔗 Live Deployments & Media

*   **🌐 Live Production Web Platform:** [Launch QATRA Application](https://app-064a8b5a-d620-463f-b4a2-2575d771368b.cleverapps.io/index.php)
*   **🎬 Comprehensive System Walkthrough:** [Demo Video on Google Drive](https://drive.google.com/file/d/1A-1Z-ZtmRV3JJHbSoVw_THqDFjfNsA65/view?usp=sharing)

---

## 🌟 Core Architectural Features

*   **Decision Support System (DSS) Validation:** Automates identity and property deed verification. The system cross-references the submitted deed number and customer’s National ID against a mock Ministry of Justice (MOJ) registry. Legitimate requests bypass manual queues, whereas identity mismatches automatically trigger a transition to the manual auditing interface.
*   **Workload-Aware Field Dispatching:** An automated dispatch algorithm that filters technicians strictly by Region and City, assigning pending inspection and installation tasks to on-duty personnel who hold the lowest active task count (`active_tasks_count`) to balance team workload.
*   **Utility Infrastructure Handling:** Dynamically refactors combined requests into separate water and sewage applications. Water services mandate physical meter serialization, while sewage service applications programmatically bypass meters, logging only physical pipeline length and diameter.

---

## 🖥️ System Portals & Dashboards

### 1. Landing Interface
The public portal providing secure registration and access points for clients and utility employees.
<p align="center">
  <img src="Home%20Page.png" width="90%" alt="QATRA Landing Page">
</p>

### 2. Client Dashboard
A dedicated portal where customers submit GIS-mapped applications, track real-time application states, view technician metrics, and settle invoices.
<p align="center">
  <img src="Customer%20Dashboard.png" width="90%" alt="QATRA Customer Dashboard">
</p>

### 3. Employee Portal Access
Secure role-based entry point for company administrative, auditing, and field staff.
<p align="center">
  <img src="Employee%20Interface.png" width="90%" alt="QATRA Employee Interface">
</p>

### 4. Auditor Panel
A comparative workspace allowing manual auditors to reconcile flagged deeds side-by-side with official mock registries and record mandatory audit decisions.
<p align="center">
  <img src="Auditor%20Dashboard.png" width="90%" alt="QATRA Auditor Dashboard">
</p>

### 5. Technical Installation Workspace
The interface used by field technicians to record physical pipeline parameters and execute hardware-to-account meter binding.
<p align="center">
  <img src="Technical%20Installation%20Dashboard.png" width="90%" alt="QATRA Technical Installation Dashboard">
</p>

### 6. Administration Console
A real-time operational dashboard visualizing active applications, regional demands, team workloads, and system metrics.
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
