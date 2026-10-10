-- =====================================================================
-- DATA DUMMY pengadaan_lju (untuk pengembangan/demo)
-- Jalankan SETELAH schema.sql
-- =====================================================================
USE pengadaan_lju;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE lokasi; TRUNCATE TABLE kebutuhan; TRUNCATE TABLE kebutuhan_item;
TRUNCATE TABLE rencana_pengadaan; TRUNCATE TABLE rencana_item; TRUNCATE TABLE penerimaan;
TRUNCATE TABLE aset; TRUNCATE TABLE distribusi; TRUNCATE TABLE mutasi_aset; TRUNCATE TABLE penghapusan_aset;
TRUNCATE TABLE kerusakan; TRUNCATE TABLE perbaikan; TRUNCATE TABLE stock_opname; TRUNCATE TABLE opname_item;
TRUNCATE TABLE atk_barang; TRUNCATE TABLE atk_transaksi; TRUNCATE TABLE atk_permintaan; TRUNCATE TABLE atk_permintaan_item;
TRUNCATE TABLE fasilitas_tiket; TRUNCATE TABLE kendaraan; TRUNCATE TABLE peminjaman_kendaraan;
TRUNCATE TABLE perawatan_kendaraan; TRUNCATE TABLE activity_log;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------- LOKASI ----------
INSERT INTO lokasi (id,nama,kode,tipe,parent_id) VALUES
(1,'Gedung A','GDA','GEDUNG',NULL),
(2,'Lantai 1','GDA-L1','LANTAI',1),
(3,'Lantai 2','GDA-L2','LANTAI',1),
(4,'Ruang Direktur','RDIR','RUANG',2),
(5,'Ruang Keuangan','RKEU','RUANG',2),
(6,'Ruang Umum','RUM','RUANG',2),
(7,'Ruang IT','RIT','RUANG',3),
(8,'Ruang Operasional','ROP','RUANG',3),
(9,'Ruang Rapat','RRAP','RUANG',2),
(10,'Gudang ATK','GDG','RUANG',1),
(11,'Halaman Parkir','PRK','RUANG',1);

-- ---------- KEBUTUHAN ----------
INSERT INTO kebutuhan (id,nomor_kebutuhan,judul,unit_pemohon,pengajuan_oleh,tanggal_pengajuan,prioritas,alasan,status,total_estimasi,verifikasi_oleh,verifikasi_tgl,persetujuan_bidang_oleh,persetujuan_bidang_tgl,persetujuan_dirut_oleh,persetujuan_dirut_tgl,finalisasi_keuangan_oleh,finalisasi_keuangan_tgl) VALUES
(1,'KBT-202610-0001','Pengadaan Laptop Staff IT','IT',4,'2026-10-01','TINGGI','Laptop lama sering mati mendadak, menghambat pekerjaan.','DITERIMA',25000000,7,'2026-10-02 09:00:00',3,'2026-10-02 13:00:00',2,'2026-10-02 15:00:00',6,'2026-10-03 09:00:00'),
(2,'KBT-202610-0002','Rehabilitasi Kursi Ruang Rapat','Kesekretariatan',5,'2026-10-03','SEDANG','Kursi ruang rapat rusak 6 unit.','DISETUJUI',4500000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(3,'KBT-202610-0003','Printer Label untuk Gudang','Pengadaan',10,'2026-10-05','SEDANG','Mendukung pencetakan label QR aset.','DIVERIFIKASI',3200000,7,'2026-10-06 10:30:00',NULL,NULL,NULL,NULL,NULL,NULL),
(4,'KBT-202610-0004','AC Server Room','Infokom',12,'2026-10-07','SEGERA','Suhu server room melebihi ambang aman.','DIAJUKAN',7500000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(5,'KBT-202609-0005','Proyektor Ruang Direktur','Keuangan',9,'2026-09-20','RENDAH','Proyektor lama lampu putus.','MASUK RENCANA PENGADAAN',6800000,7,'2026-09-21 08:00:00',3,'2026-09-22 09:00:00',2,'2026-09-22 14:00:00',NULL,NULL);

INSERT INTO kebutuhan_item (kebutuhan_id,nama_barang,spesifikasi,satuan,jumlah,estimasi_harga,estimasi_total) VALUES
(1,'Laptop Lenovo ThinkPad E14','i5/16GB/512GB SSD/Win 11','unit',2,12500000,25000000),
(2,'Servis & Ganti Busa Kursi','Kulit sintetis, rangka baja','set',6,750000,4500000),
(3,'Printer Label Thermal','Zebra ZD230 atau setara','unit',1,3200000,3200000),
(4,'AC Split 2 PK','Panasonic/Daikin 2 PK','unit',1,7500000,7500000),
(5,'Proyektor Epson EB-X51','3800 lumens, XGA','unit',1,6800000,6800000);

-- ---------- RENCANA PENGADAAN ----------
INSERT INTO rencana_pengadaan (id,nomor_rencana,judul,tanggal,unit_pemohon,prioritas,anggaran,estimasi_total,sumber_anggaran,status,kebutuhan_ids,diajukan_oleh,approve_kadiv_oleh,approve_dop_oleh,final_finance_oleh) VALUES
(1,'RP-202610-0001','Rencana Pengadaan Q4 - Sarana Kantor','2026-10-06','IT, Kesekretariatan, Infokom','TINGGI',45000000,40800000,'APBN Perusahaan 2026','DALAM PROSES PENGADAAN','1,2,4',7,3,2,9);

INSERT INTO rencana_item (rencana_id,nama_barang,spesifikasi,satuan,jumlah,estimasi_harga,estimasi_total,asal_kebutuhan_id) VALUES
(1,'Laptop Lenovo ThinkPad E14','i5/16GB/512GB SSD','unit',2,12500000,25000000,1),
(1,'Set Servis Kursi Rapat','6 set','set',6,750000,4500000,2),
(1,'AC Split 2 PK','Untuk server room','unit',1,7500000,7500000,4),
(1,'Proyektor Epson EB-X51','3800 lumens','unit',1,3800000,3800000,5);

-- ---------- PENERIMAAN ----------
INSERT INTO penerimaan (id,nomor_penerimaan,tanggal,rencana_id,kebutuhan_id,nama_barang,vendor,no_faktur,jumlah_diterima,jumlah_diminta,harga_satuan,total_nilai,ck_jumlah_sesuai,ck_spesifikasi_sesuai,ck_kondisi_baik,ck_dokumen_lengkap,status,diterima_oleh) VALUES
(1,'PRB-202610-0001','2026-10-08',1,1,'Laptop Lenovo ThinkPad E14','PT Sentra Komputer','INV/2026/10/011',2,2,12500000,25000000,1,1,1,1,'DITERIMA',7),
(2,'PRB-202610-0002','2026-10-09',1,NULL,'AC Split 2 PK','CV ABC Service','INV/2026/10/021',1,1,7500000,7500000,1,1,0,1,'DITERIMA SEBAGIAN',7);

-- ---------- ASET ----------
INSERT INTO aset (id,kode_aset,nama,kategori,merk_type,tanggal_perolehan,nilai_perolehan,lokasi_id,pengguna_id,unit,kondisi,status,sumber) VALUES
(1,'AST-RIT-IT-2026-000001','Laptop Lenovo ThinkPad E14','Peralatan IT','Lenovo','2026-10-08',12500000,7,4,'IT','BAIK','AKTIF','Pengadaan'),
(2,'AST-RIT-IT-2026-000002','Laptop Lenovo ThinkPad E14','Peralatan IT','Lenovo','2026-10-08',12500000,7,12,'IT','BAIK','AKTIF','Pengadaan'),
(3,'AST-RKEU-KEU-2026-000003','AC Split 1.5 PK','Peralatan Rumah Tangga','Daikin','2024-05-10',4200000,5,6,'Keuangan','BAIK','AKTIF','Pengadaan'),
(4,'AST-RRAP-SET-2023-000004','Kursi Rapat Kulit','Furnitur','Olympic','2023-01-15',850000,9,NULL,'Kesekretariatan','RUSAK RINGAN','AKTIF','Pengadaan'),
(5,'AST-RDIR-DIR-2022-000005','Meja Direktur Jati','Furnitur','Kayu Jati','2022-07-20',8500000,4,1,'Direksi','BAIK','AKTIF','Pengadaan'),
(6,'AST-GDG-PGN-2024-000006','Rak Gudang Baja','Inventaris Gudang','Protera','2024-03-01',1200000,10,10,'Pengadaan','BAIK','AKTIF','Pengadaan'),
(7,'AST-RIT-IT-2021-000007','PC Rakit Kerja','Peralatan IT','Rakit Lokal','2021-11-11',7000000,7,4,'IT','RUSAK BERAT','RUSAK','Pengadaan'),
(8,'AST-RUM-SDM-2023-000008','Scanner Dokumen','Peralatan IT','Epson DS-670','2023-06-01',3500000,6,8,'SDM','BAIK','DIPINDAHKAN','Pengadaan'),
(9,'AST-ROP-OPS-2020-000009','Proyektor BenQ','Peralatan IT','BenQ MX535','2020-02-02',5500000,8,10,'Pengadaan','BAIK','AKTIF','Pengadaan'),
(10,'AST-RKEU-KEU-2019-000010','Mesin Absensi Sidik Jari','Peralatan Kantor','Prometech','2019-08-08',2800000,5,8,'Keuangan','RUSAK BERAT','DIHAPUS','Pengadaan');

-- ---------- DISTRIBUSI ----------
INSERT INTO distribusi (id,nomor_distribusi,aset_id,permintaan_oleh,unit_tujuan,lokasi_tujuan,keperluan,status,setujui_oleh,terima_tgl) VALUES
(1,'DST-202610-0001',2,12,'IT',7,'Komputer kerja staff baru','DITERIMA',3,'2026-10-09 10:00:00'),
(2,'DST-202610-0002',9,10,'Operasional',8,'Presentasi lapangan','DISSETUJUI',3,NULL);

-- ---------- MUTASI ----------
INSERT INTO mutasi_aset (aset_id,tanggal,unit_sebelum,lokasi_sebelum,pengguna_sebelum,unit_sesudah,lokasi_sesudah,pengguna_sesudah,alasan,oleh) VALUES
(8,'2026-10-04','SDM','Ruang SDM',8,'SDM','Ruang Umum',8,'Pemindahan akibat renovasi ruang SDM',7);

-- ---------- PENGHAPUSAN ----------
INSERT INTO penghapusan_aset (nomor_penghapusan,aset_id,alasan,uraian,diajukan_oleh,status,berita_acara_no,berita_acara_tgl) VALUES
('HPS-202609-0001',10,'RUSAK BERAT','Mesin absensi tidak dapat diperbaiki, suku cadang tidak tersedia.',7,'DIHAPUS','BA/9/HPS/2026','2026-09-30');

-- ---------- KERUSAKAN ----------
INSERT INTO kerusakan (id,nomor_laporan,aset_id,judul,deskripsi,pelapor,tanggal_lapor,status,diagnosis,perbaikan_id) VALUES
(1,'LKR-202610-0001',7,'PC Kerja tidak menyala','PC rakit tidak mau menyala sama sekali setelah listrik padam.',4,'2026-10-05','DALAM PERBAIKAN','Power supply dan motherboard perlu diganti.',1),
(2,'LKR-202610-0002',4,'Kursi rapat busa amblas','Busa kursi rapat No.3 amblas sehingga tidak nyaman.',5,'2026-10-06','DILAPORKAN',NULL,NULL),
(3,'LKR-202610-0003',3,'AC Ruang Keuangan kurang dingin','AC menyala tapi tidak dingin sejak 2 hari terakhir.',6,'2026-10-07','DIVERIFIKASI',NULL,NULL);

-- ---------- PERBAIKAN ----------
INSERT INTO perbaikan (nomor,aset_id,kerusakan_id,tanggal,item,kerusakan_desc,vendor,teknisi,biaya,tindakan,status,dicatat_oleh) VALUES
('PRK-202610-0001',7,1,'2026-10-06','PC Rakit Kerja','Tidak menyala','CV Mitra Teknik','Rudi',450000,'Penggantian PSU 450W, pengecekan RAM','DALAM PERBAIKAN',7),
('PRK-202609-0002',3,NULL,'2026-09-15','AC Ruang Keuangan','Pembersihan rutin','ABC Service','Joko',250000,'Cuci indoor/outdoor, cek freon','SELESAI',7),
('PRK-202608-0003',9,NULL,'2026-08-20','Proyektor BenQ','Fan berisik','Servos','Andri',180000,'Pembersihan fan + oli','SELESAI',7);

-- ---------- STOCK OPNAME ----------
INSERT INTO stock_opname (nomor_opname,judul,tanggal_mulai,lokasi_filter,unit_filter,pelaksana,status,total_data,total_terverifikasi,total_cocok,total_tidak_cocok,total_tidak_ditemukan) VALUES
('SO-202609-0001','Stock Opname Triwulan III - Gedung A','2026-09-25','Gedung A',NULL,7,'SELESAI',9,9,8,1,0);

INSERT INTO opname_item (opname_id,aset_id,kode_scan,hasil,kondisi_hasil,catatan) VALUES
(1,1,'AST-RIT-IT-2026-000001','COCOK','BAIK',NULL),
(1,3,'AST-RKEU-KEU-2026-000003','COCOK','BAIK',NULL),
(1,8,'AST-RUM-SDM-2023-000008','TIDAK COCOK','BAIK','Tercatat di Ruang SDM, fisik ada di Ruang Umum');

-- ---------- ATK ----------
INSERT INTO atk_barang (id,kode_barang,nama,kategori,satuan,stok,stok_minimum,harga_satuan,lokasi_simpan) VALUES
(1,'ATK-0001','Kertas HVS A4 80gr','Kertas','rim',42,20,52000,'Gudang ATK'),
(2,'ATK-0002','Pulpen Standard AE7','Alat Tulis','pcs',180,50,3500,'Gudang ATK'),
(3,'ATK-0003','Spidol Whiteboard','Alat Tulis','pcs',24,10,8000,'Gudang ATK'),
(4,'ATK-0004','Map Snelhecter','Kearsipan','pcs',15,20,4500,'Gudang ATK'),
(5,'ATK-0005','Stapler HD-10','Mesin Kantor','pcs',12,5,35000,'Gudang ATK'),
(6,'ATK-0006','Tinta Printer Epson 003','Pengganda','botol',3,5,95000,'Gudang ATK'),
(7,'ATK-0007','Ordner Folio','Kearsipan','pcs',30,10,28000,'Gudang ATK'),
(8,'ATK-0008','Buku Tulis Klim 58lbr','Kertas','buku',60,25,12000,'Gudang ATK');

INSERT INTO atk_transaksi (atk_barang_id,tipe,jumlah,ref_type,unit,keterangan,oleh) VALUES
(1,'SALDO_AWAL',60,'saldo','Gudang ATK','Saldo awal 2026',7),
(1,'MASUK',20,'pembelian','Gudang ATK','Pembelian PT Gramedia',7),
(1,'KELUAR',38,'permintaan','Keuangan','Permintaan bulanan divisi keuangan',7),
(2,'SALDO_AWAL',200,'saldo','Gudang ATK','Saldo awal',7),
(2,'KELUAR',20,'permintaan','Sekretariat',NULL,7),
(6,'MASUK',10,'pembelian','Gudang ATK','Pembelian tinta',7),
(6,'KELUAR',7,'permintaan','Bisnis',NULL,7);

INSERT INTO atk_permintaan (id,nomor_permintaan,unit_pemohon,pemohon,tanggal,keperluan,status,verifikasi_oleh,terima_oleh) VALUES
(1,'REQ-202610-0001','Keuangan',6,'2026-10-02','Kebutuhan cetak laporan triwulan','DISERAHKAN',7,6),
(2,'REQ-202610-0002','IT',12,'2026-10-06','ATK ruang server','MENUNGGU PROSES',NULL,NULL),
(3,'REQ-202610-0003','Kesekretariatan',5,'2026-10-07','Persiapan rapat direksi','STOK TERSEDIA',7,NULL),
(4,'REQ-202610-0004','SDM',8,'2026-10-08','Formulir karyawan baru','MENUNGGU PENGAMBILAN',7,NULL),
(5,'REQ-202610-0005','Pengadaan',10,'2026-10-09','Label aset','STOK KOSONG',7,NULL);

INSERT INTO atk_permintaan_item (permintaan_id,atk_barang_id,jumlah_minta,jumlah_setuju) VALUES
(1,1,10,10),(1,2,15,15),
(2,1,5,0),(2,3,4,0),
(3,2,20,20),(3,7,5,5),
(4,8,12,12),
(5,6,4,0);

-- ---------- FASILITAS ----------
INSERT INTO fasilitas_tiket (nomor_tiket,lokasi_id,lokasi_text,jenis,masalah,prioritas,pelapor,tanggal_lapor,status,ditugaskan_ke) VALUES
('FSL-202610-0001',9,'Ruang Rapat','AC','AC ruang rapat tidak dingin sejak Senin','NORMAL',5,'2026-10-06','PENUGASAN','Teksi Bangunan'),
('FSL-202610-0002',6,'Ruang Umum','LAMPU','2 lampu neon berkedip','RENDAH',8,'2026-10-07','LAPORAN BARU',NULL),
('FSL-202610-0003',NULL,'Toilet Lantai 2','TOILET','Kran air bocor','NORMAL',12,'2026-10-08','VERIFIKASI',NULL),
('FSL-202609-0004',4,'Ruang Direktur','INSTALASI LISTRIK','Stop kontak dekat meja mati','TINGGI',1,'2026-09-28','SELESAI',NULL);

-- ---------- KENDARAAN ----------
INSERT INTO kendaraan (id,tnkb,merk_type,tahun,warna,nomor_rangka,nomor_mesin,km_terakhir,kondisi,lokasi,penanggung_jawab,status,jadwal_servis_km) VALUES
(1,'BE 1234 XY','Toyota Innova Reborn',2021,'Hitam','MHFXW98GMC0123450','1TR1234567',48500,'BAIK','Garasi Gedung A',10,'TERSEDIA',50000),
(2,'BE 5678 Z','Mitsubishi L300 Dump',2019,'Putih','MMML300000KM56789','4D5678901',92300,'BAIK','Gudang',10,'DIGUNAKAN',95000),
(3,'B 9012 ABC','Honda HR-V',2022,'Merah','MHRV1234KM90123','L15123456',31200,'BAIK','Garasi Gedung A',8,'SERVICE',35000),
(4,'BE 3456 LM','Isuzu Panther',2018,'Silver','ISUPANTHER3456LM','4EE123456',145000,'RUSAK RINGAN','Garasi Gedung A',10,'TERSEDIA',150000),
(5,'BE 7890 NP','Toyota Hilux Double Cab',2020,'Putih','JTMRK78GMC78901','2KD789012',67800,'BAIK','Site Proyek',10,'DIPINJAM',70000);

-- ---------- PEMINJAMAN ----------
INSERT INTO peminjaman_kendaraan (nomor_pinjam,kendaraan_id,pemohon,tanggal_pinjam,tanggal_kembali,tujuan,pengemudi,penumpang,km_awal,km_akhir,bbm,status,setujui_oleh,kondisi_kembali) VALUES
('PJM-202610-0001',5,10,'2026-10-08','2026-10-10','Angkut material proyek Natar', 'Pak Slamat','Tim Proyek 3 orang',67800,NULL,120000,'DIGUNAKAN',3,NULL),
('PJM-202610-0002',2,6,'2026-10-09','2026-10-09','Survey vendor Bandar Lampung', 'Bu Sari','Divisi Keuangan 2 orang',92300,92380,85000,'DISETUJUI',3,NULL),
('PJM-202609-0003',1,5,'2026-09-20','2026-09-21','Antar direksi meeting', 'Pak Warno','Direksi',48100,48300,150000,'DITERIMA',3,'Kondisi baik, bersih');

-- ---------- PERAWATAN KENDARAAN ----------
INSERT INTO perawatan_kendaraan (kendaraan_id,tanggal,jenis,km,biaya,vendor,catatan,oleh) VALUES
(1,'2026-01-10','Ganti Oli',40000,380000,'Bengkel Toyota','Oli + filter oli',10),
(1,'2026-04-20','Servis Berkala',45000,750000,'Bengkel Toyota','Servis 40rb km',10),
(1,'2026-10-03','Servis',48500,420000,'Bengkel Toyota','Ganti oli mesin & balance roda',10),
(3,'2026-09-15','Ganti Kampas Rem',30000,650000,'Bengkel Honda','Depan & belakang',10),
(4,'2026-08-01','Tune Up',143000,500000,'Bengkel Umum',NULL,10);
