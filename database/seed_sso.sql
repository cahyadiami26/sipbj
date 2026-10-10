-- =====================================================================
-- DATABASE SSO: sso_lju  (tabel users - dimiliki portal SSO lju.co.id)
-- Struktur sama persis dengan produksi; dibuat di sini untuk pengembangan.
-- =====================================================================
CREATE DATABASE IF NOT EXISTS sso_lju CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE sso_lju;

CREATE TABLE IF NOT EXISTS users (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `nik` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `position` enum('Direktur Utama','Direktur Operasional','Kepala Bidang','Kepala Divisi','Manager','Sekretaris','Staff','System Administrator','Corporate Secretary','Sekretaris Direksi','Sekretaris Komisaris') DEFAULT NULL,
  `division` enum('Keuangan','Pengadaan','Keamanan','Infokom','SDM','Direksi','IT','Legal','Kesekretariatan','Jasa Konstruksi','Pengangkutan & Pergudangan','Perdagangan','Energi & Migas','Direksi') DEFAULT NULL,
  `department` enum('Direksi','Keuangan & Umum','Bisnis','Sekretariat','Pengawas Internal') DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user','op_arsip') DEFAULT 'user',
  `avatar` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `rfid` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `webmail_user` varchar(200) NOT NULL,
  `webmail_pass` varchar(16) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO users (id,nik,name,email,position,division,department,username,password,role,avatar,is_active,webmail_user,webmail_pass) VALUES
(1,'LJU-0001','Rahmat Hidayat','rahmat@lju.co.id','Direktur Utama','Direksi','Direksi','rahmat',SHA2('devpass',256),'admin','1.jpg',1,'rahmat@lju.co.id','change-me'),
(2,'LJU-0002','Sri Wulandari','sri@lju.co.id','Direktur Operasional','Direksi','Direksi','sri',SHA2('devpass',256),'user','2.jpg',1,'sri@lju.co.id','change-me'),
(3,'LJU-0003','Andi Prasetyo','andi@lju.co.id','Kepala Divisi','Pengadaan','Keuangan & Umum','andi',SHA2('devpass',256),'user','3.jpg',1,'andi@lju.co.id','change-me'),
(4,'LJU-0004','Budi Santoso','budi@lju.co.id','Kepala Bidang','IT','Bisnis','budi',SHA2('devpass',256),'user','4.jpg',1,'budi@lju.co.id','change-me'),
(5,'LJU-0005','Citra Melati','citra@lju.co.id','Kepala Divisi','Kesekretariatan','Sekretariat','citra',SHA2('devpass',256),'user','5.jpg',1,'citra@lju.co.id','change-me'),
(6,'LJU-0006','Dewi Anggraini','dewi@lju.co.id','Manager','Keuangan','Keuangan & Umum','dewi',SHA2('devpass',256),'user','6.jpg',1,'dewi@lju.co.id','change-me'),
(7,'LJU-0007','Eko Nugroho','eko@lju.co.id','Staff','Pengadaan','Keuangan & Umum','eko',SHA2('devpass',256),'user','7.jpg',1,'eko@lju.co.id','change-me'),
(8,'LJU-0008','Fajar Ramadhan','fajar@lju.co.id','Staff','SDM','Keuangan & Umum','fajar',SHA2('devpass',256),'user','8.jpg',1,'fajar@lju.co.id','change-me'),
(9,'LJU-0009','Gita Permata','gita@lju.co.id','Kepala Bidang','Keuangan','Keuangan & Umum','gita',SHA2('devpass',256),'user','9.jpg',1,'gita@lju.co.id','change-me'),
(10,'LJU-0010','Hendra Wijaya','hendra@lju.co.id','Kepala Bidang','Pengadaan','Bisnis','hendra',SHA2('devpass',256),'user','10.jpg',1,'hendra@lju.co.id','change-me'),
(11,'LJU-0011','Maya Sari','maya@lju.co.id','System Administrator','IT','Bisnis','maya',SHA2('devpass',256),'admin','11.jpg',1,'maya@lju.co.id','change-me'),
(12,'LJU-0012','Nanda Pratama','nanda@lju.co.id','Staff','Infokom','Bisnis','nanda',SHA2('devpass',256),'user','12.jpg',1,'nanda@lju.co.id','change-me');
