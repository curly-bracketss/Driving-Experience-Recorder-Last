**🚗 Driving Experience Recorder**


The Driving Experience Recorder is a web-based application designed to help users record, organize, and analyze driving sessions based on real-world conditions such as weather, traffic, road surface, visibility, parking type, manoeuvres, and time of day.

This project transforms subjective driving experiences into structured, analyzable data, making it useful for learning, safety analysis, research, and academic purposes.

**🌟 Project Objectives**

Record detailed driving session data

Ensure accurate time and distance validation

Store data securely in a relational database

Provide a clear and responsive user interface

Enable future data analysis and summary reports
**
🧠 Core Features**

🗓️ Session logging with date, start time, end time, and mileage

🌦️ Driving conditions tracking

Weather

Traffic density

Road surface types (multiple selection)

Visibility conditions

Parking type

Driving manoeuvres

Part of day

🔒 Server-side validation for all inputs

🗄️ Normalized database structure with junction tables

📱 Responsive UI (mobile & desktop friendly)

🧭 Navigation system with mobile menu

📊 Expandable for summaries and analytics

**🛠️ Tech Stack**
**Frontend**

HTML5

Tailwind CSS

Vanilla JavaScript

Responsive design principles
**
Backend**

PHP (Procedural + Prepared Statements)

MySQL

Transaction-based inserts

Secure form handling

Hosting

AlwaysData

Linux-based environment

**🗃️ Database Design**

The database follows relational normalization principles:

drivingSession — main session data

Lookup tables:

weather

traffic

roadSurfaceType

visibility

parking

manoeuvre

dayPart

Junction table:

drivingSession_roadSurfaceType (many-to-many)

This structure avoids redundancy and supports scalability.

**📂 Project Structure**
/www
├── form.php
├── record.php
├── experiences.php
├── summary.php
├── db.php
├── README.md
├── src/
│   ├── DrivingExperience.php
├── assets/
│   ├── road.jpeg
│   ├── weather.jpeg
│   ├── traffic.jpeg
│   ├── parking.jpeg
│   ├── manoeuvres.jpeg
│   └── visibility.jpeg

**✅ Data Validation Rules**

All fields are required

Start time must be earlier than end time

Mileage must be a positive number

All selected options must exist in the database

Transactions ensure data integrity
**
🚀 How to Run the Project**

Upload the project to the /www directory on AlwaysData

Import the provided SQL database schema

Configure database credentials in db.php

Ensure image assets are placed in /www/assets

Access the application via:

https://nazrin33.alwaysdata.net/HWPlast/form.php
**
📈 Future Improvements**

User authentication and profiles

Data visualization (charts, statistics)

Export data to CSV / PDF

Filtering and searching driving sessions

API support for mobile applications

**🎓 Academic Context**

This project was developed as part of a web development / backend programming course, focusing on:

PHP–MySQL integration

Secure data handling

Relational database design

Real-world problem modeling
**
👩‍💻 Author**

Nazrin Azizli
Computer Science Student
Driving Experience Recorder – Backend & Frontend Implementation
