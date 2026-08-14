# QATRA: Enterprise Smart Order Management System

**QATRA** is a state-driven, enterprise-grade digital platform designed to digitize and automate the complete lifecycle of water and sewage service applications. The project replaces fragmented legacy paperwork with a controlled, highly optimized digital nervous system.

This system was developed as part of the **Summer Training Program** at **Qassim University** in collaboration with the **National Water Company (NWC)**, under the esteemed supervision of **Abdulrahman Alnafisi** (Senior Applications Support Specialist at NWC).

---

## 🎬 Live System Demo & Walkthrough

We have recorded a comprehensive video demonstration walking through the entire operational lifecycle of the QATRA system:
*   **What is shown in the video:** Creating and registering a new customer account, logging in, submitting a utility request with GIS mapping, automated DSS background auditing, the Auditor dashboard, the Field Inspector's checklist, customer invoice payment, and finally, the Installation Technician's dashboard executing and completing the installation with hardware meter binding.
*   **[👉 CLICK HERE TO WATCH THE COMPLETE SYSTEM DEMO VIDEO 🚀](#)** *(Note: You can replace this link with your actual video file link!)*

---

## 🌟 Core Features & Engineering Highlights

*   **End-to-End Service Governance:** Programmatically guides applications through 8 deterministic phases (Identity Validation, Spatial GIS Pinning, Deed DSS matching, Field Inspection, Auto-Pricing, Mock Billing, Unified Account Creation, and Physical Installation) [2-4].
*   **Deep Decision Support System (DSS):** Mitigates property deed forgery and identity fraud by cross-matching deed records and national IDs against a Mock Ministry of Justice database in real-time [5-7].
    *   *Strict Rejection:* Automatic rejection if the National ID does not match the deed owner [7, 8].
    *   *Discrepancy Escalation:* Minor spelling or name anomalies route the request directly to the **Manual Auditor** interface for human override and reconciliation [8].
*   **Smart Geofenced Dispatching:** Automatically assigns field tasks to available technicians registered in the same City who have the lowest workload (`active_tasks_count`), balancing human resources efficiently [9].
*   **Physical Pipeline Refactoring:** Handles structural differences dynamically; Water services enforce physical meter serialization, while Sewage services programmatically bypass meters, logging only pipeline length and diameter attributes [10].
*   **Scalable Database Engineering:** Built upon an 18-table normalized MySQL database. Dynamic security operations (OTP codes and user notifications) are programmatically decoupled in PHP to prevent database-level transaction locks [10, 11].

---

## 🖥️ System Portals & Dashboards (Visual Showcase)

### 1. The Client Dashboard
Customers can seamlessly register, track their application states, view interactive GIS coordinates, and print invoices.
<p align="center">
  <img src="qatra_dashboard_mockup.jpg" width="85%" alt="QATRA Client Dashboard Mockup">
</p>

### 2. The Manual Auditor Console
A split-screen workspace where auditors can compare uploaded deed documents against Ministry of Justice records side-by-side.
<p align="center">
  <img src="auth_usecase_v2.png" width="85%" alt="Auditor Workspace">
</p>

### 3. Smart System Sequence (8-Phase Workflow)
The detailed state transitions and communications across all actors and subsystems.
<p align="center">
  <img src="qatra_system_sequence_v25.png" width="85%" alt="System Sequence Diagram">
</p>

---

## 💾 Relational Database Schema (ERD)
The underlying architecture comprises 18 normalized tables representing relational structures with cascading behaviors.
<p align="center">
  <img src="updated-erd.png" width="85%" alt="Normalized Database ERD Schema">
</p>

---

## 👥 Developers & Team Members

*   **Alanoud Abdullah Albuti** (Lead Developer & System Architect)
*   **Thikra Nasser Alyahya** (Database Engineer & Backend Developer)
*   **Aroob Abdulaziz Altuwayjiri** (Backend Developer & Quality Assurance)
*   **Norah Ali Aldhabaan** (Frontend & UI/UX Developer)
*   **Project Supervisor:** Abdulrahman Alnafisi (NWC Application Support Specialist)
