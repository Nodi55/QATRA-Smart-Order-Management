# QATRA: Enterprise Smart Order Management System

**QATRA** is a state-driven, enterprise-grade digital platform designed to digitize and automate the complete lifecycle of water and sewage service applications. The platform replaces manual, paper-heavy coordinating pipelines with a controlled digital nervous system, integrating real-time verification and automated field dispatching.

Developed in collaboration with **Qassim University** and the **National Water Company (NWC)** under the professional supervision of **Abdulrahman Alnafisi** (Senior Applications Support Specialist, NWC).

---

## 🔗 Live Links & Media

*   **🌐 Live Production Deployment:** [QATRA Web Platform](https://app-064a8b5a-d620-463f-b4a2-2575d771368b.cleverapps.io/index.php)
*   **🎬 Video Demonstration & Walkthrough:** [System Demo Video (Google Drive)](https://drive.google.com/file/d/1A-1Z-ZtmRV3JJHbSoVw_THqDFjfNsA65/view?usp=sharing)

---

## 🌟 Core System Pillars

1. **Automated Decision Support System (DSS):** Performs 1:1 real-time cross-matching of customer identities and land deeds against database registries to prevent document forgery and identity fraud. Mismatched applications are automatically routed to the Manual Auditor interface, while verified applications progress directly to field planning.
2. **Geofenced Field Dispatch Engine:** An automated, geofenced workload-aware dispatching algorithm that routes inspection and installation tasks to on-duty field technicians strictly within the property’s Region and City, prioritizing the technician with the lowest active workload count (`active_tasks_count`).
3. **Physical Refactoring & Billing Integration:** Dynamically distinguishes between Water (requiring physical meter serialization) and Sewage (which bypasses meters, logging pipe dimensions). Automatically splits combined service requests into independent lifecycles for decoupled field progress.

---

## 🖥️ System Portals & Visual Showcase

### 1. Main Landing Portal
The entry point for customers and employees, showcasing the unified digital nervous system.
<p align="center">
  <img src="Home Page.png" width="90%" alt="QATRA Home Page">
</p>

### 2. Customer Dashboard & Trackers
A responsive portal where customers can submit coordinates via Leaflet.js mapping, upload land deeds, track real-time application states, view technician metadata, and settle invoices.
<p align="center">
  <img src="Customer Dashboard.png" width="90%" alt="QATRA Customer Dashboard">
</p>

### 3. Employee Portal Access
Secure role-based entry for corporate personnel (Administrators, Manual Auditors, and Field Crew).
<p align="center">
  <img src="Employee Interface.png" width="90%" alt="QATRA Employee Interface">
</p>

### 4. Manual Auditor Console
A split-screen workspace where auditors reconcile flagged deeds side-by-side with Ministry of Justice records to approve or reject requests with mandatory reasons.
<p align="center">
  <img src="Auditor Dashboard.png" width="90%" alt="QATRA Auditor Dashboard">
</p>

### 5. Field Technician Workspaces
Mobile-optimized views allowing field crews to perform readiness checklists on-site, upload site photographs, and execute hardware meter binding.
<p align="center">
  <img src="Technical Installation Dashboard.png" width="90%" alt="QATRA Technical Installation Dashboard">
</p>

### 6. Administrative Insights & Performance Monitoring
A real-time data-driven dashboard displaying operational metrics, city-level demand, supervisor alerts, and active technician workloads.
<p align="center">
  <img src="Admin Dashboard.png" width="90%" alt="QATRA Admin Dashboard">
</p>

---

## ⚙️ Technology Stack

*   **Backend:** Native PHP (State-driven transitions, automated workflow splitting, security configurations)
*   **Database:** MySQL (18 highly normalized tables with relational foreign-key constraints)
*   **Frontend:** Bootstrap & CSS (Fully responsive multi-portal interface)
*   **GIS Engine:** Leaflet.js (Dynamic spatial mapping & coordinate pinning)
*   **Hosting:** Clever Cloud Staging Environment

---

## 💾 Relational Database Schema
The underlying database comprises 18 tables, separating core operations (Applications, Invoices, Accounts, Meters) from security and notification queues (OTP, SMS logs) to ensure high scalability and prevent database-level transaction locks.

*   `customer`, `company_employee`, `system_role`, `employee_roles` (Identity & RBAC)
*   `application`, `application_history` (State-driven tracking)
*   `field_inspection`, `installation_task`, `meter` (Field operations & hardware binding)
*   `invoice`, `unified_account`, `activated_service` (Financials & active service provisioning)
*   `moj_record` (External sovereign registry checkups)
*   `otp_code`, `notification` (Decoupled auxiliary messaging services)

---

## 👥 Project Team & Personnel

*   **Alanoud Abdullah Albuti** (Lead Developer & System Architect)
*   **Thikra Nasser Alyahya** (Database Engineer & Backend Developer)
*   **Aroob Abdulaziz Altuwayjiri** (Backend Developer & Quality Assurance)
*   **Norah Ali Aldhabaan** (Frontend & UI/UX Developer)
*   **Project Supervisor:** Abdulrahman Alnafisi (Senior Applications Support Specialist, National Water Company)
