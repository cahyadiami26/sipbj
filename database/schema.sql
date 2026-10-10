-- =====================================================================
-- Sistem Pengadaan Barang/Jasa - PT. Lampung Jasa Utama
-- Database aplikasi: pengadaan_lju
-- (tabel users berada di database SSO terpisah: sso_lju, satu mesin)
-- =====================================================================
CREATE DATABASE IF NOT EXISTS pengadaan_lju CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE pengadaan_lju;

-- ---------------------------------------------------------------
-- MASTER LOKASI: Gedung -> Lantai -> Ruang (hierarki parent/child)
-- ---------------------------------------------------------------
CREATE TABLE lokasi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  kode VARCHAR(20) NOT NULL UNIQUE,
  tipe ENUM('GEDUNG','LANTAI','RUANG') NOT NULL DEFAULT 'RUANG',
  parent_id INT NULL,
  keterangan VARCHAR(255) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_id) REFERENCES lokasi(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- KEBUTUHAN (Pengajuan Kebutuhan) + item
-- DRAFT > DIAJUKAN > DIVERIFIKASI > DISETUJUI >
-- DALAM PROSES PENGADAAN / MASUK RENCANA PENGADAAN > DITERIMA
-- ---------------------------------------------------------------
CREATE TABLE kebutuhan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_kebutuhan VARCHAR(50) NOT NULL UNIQUE,
  judul VARCHAR(200) NOT NULL,
  unit_pemohon VARCHAR(100) NOT NULL,
  pengajuan_oleh INT NOT NULL,
  tanggal_pengajuan DATE NOT NULL,
  prioritas ENUM('RENDAH','SEDANG','TINGGI','SEGERA') DEFAULT 'SEDANG',
  alasan TEXT NULL,
  status ENUM('DRAFT','DIAJUKAN','DIVERIFIKASI','DISETUJUI','DALAM PROSES PENGADAAN','MASUK RENCANA PENGADAAN','DITERIMA','DITOLAK') DEFAULT 'DRAFT',
  verifikasi_oleh INT NULL, verifikasi_catatan TEXT NULL, verifikasi_tgl DATETIME NULL,
  persetujuan_bidang_oleh INT NULL, persetujuan_bidang_catatan TEXT NULL, persetujuan_bidang_tgl DATETIME NULL,
  persetujuan_dirut_oleh INT NULL, persetujuan_dirut_catatan TEXT NULL, persetujuan_dirut_tgl DATETIME NULL,
  finalisasi_keuangan_oleh INT NULL, finalisasi_keuangan_catatan TEXT NULL, finalisasi_keuangan_tgl DATETIME NULL,
  total_estimasi DECIMAL(15,2) DEFAULT 0,
  catatan TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE kebutuhan_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kebutuhan_id INT NOT NULL,
  nama_barang VARCHAR(200) NOT NULL,
  spesifikasi TEXT NULL,
  satuan VARCHAR(30) DEFAULT 'unit',
  jumlah INT NOT NULL DEFAULT 1,
  estimasi_harga DECIMAL(15,2) DEFAULT 0,
  estimasi_total DECIMAL(15,2) DEFAULT 0,
  keterangan VARCHAR(255) NULL,
  FOREIGN KEY (kebutuhan_id) REFERENCES kebutuhan(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- RENCANA PENGADAAN + item
-- DRAFT > DIAJUKAN > DISETUJUI > DALAM PROSES PENGADAAN > DITERIMA
-- ---------------------------------------------------------------
CREATE TABLE rencana_pengadaan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_rencana VARCHAR(50) NOT NULL UNIQUE,
  judul VARCHAR(200) NOT NULL,
  tanggal DATE NOT NULL,
  unit_pemohon VARCHAR(200) NOT NULL,
  prioritas ENUM('RENDAH','SEDANG','TINGGI','SEGERA') DEFAULT 'SEDANG',
  anggaran DECIMAL(15,2) DEFAULT 0,
  estimasi_total DECIMAL(15,2) DEFAULT 0,
  sumber_anggaran VARCHAR(100) NULL,
  status ENUM('DRAFT','DIAJUKAN','DISETUJUI','DALAM PROSES PENGADAAN','DITERIMA','DITOLAK') DEFAULT 'DRAFT',
  diajukan_oleh INT NULL, diajukan_tgl DATETIME NULL,
  approve_kadiv_oleh INT NULL, approve_kadiv_catatan TEXT NULL, approve_kadiv_tgl DATETIME NULL,
  approve_dop_oleh INT NULL, approve_dop_catatan TEXT NULL, approve_dop_tgl DATETIME NULL,
  final_finance_oleh INT NULL, final_finance_catatan TEXT NULL, final_finance_tgl DATETIME NULL,
  kebutuhan_ids VARCHAR(255) NULL,
  catatan TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE rencana_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rencana_id INT NOT NULL,
  nama_barang VARCHAR(200) NOT NULL,
  spesifikasi TEXT NULL,
  satuan VARCHAR(30) DEFAULT 'unit',
  jumlah INT NOT NULL DEFAULT 1,
  estimasi_harga DECIMAL(15,2) DEFAULT 0,
  estimasi_total DECIMAL(15,2) DEFAULT 0,
  asal_kebutuhan_id INT NULL,
  FOREIGN KEY (rencana_id) REFERENCES rencana_pengadaan(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- PENERIMAAN BARANG/JASA (+ checklist)
-- ---------------------------------------------------------------
CREATE TABLE penerimaan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_penerimaan VARCHAR(50) NOT NULL UNIQUE,
  tanggal DATE NOT NULL,
  rencana_id INT NULL,
  kebutuhan_id INT NULL,
  nama_barang VARCHAR(200) NOT NULL,
  vendor VARCHAR(150) NULL,
  no_faktur VARCHAR(80) NULL,
  tgl_faktur DATE NULL,
  jumlah_diterima INT NOT NULL DEFAULT 1,
  jumlah_diminta INT DEFAULT 0,
  harga_satuan DECIMAL(15,2) DEFAULT 0,
  total_nilai DECIMAL(15,2) DEFAULT 0,
  ck_jumlah_sesuai TINYINT(1) DEFAULT 0,
  ck_spesifikasi_sesuai TINYINT(1) DEFAULT 0,
  ck_kondisi_baik TINYINT(1) DEFAULT 0,
  ck_dokumen_lengkap TINYINT(1) DEFAULT 0,
  status ENUM('DITERIMA','DITERIMA SEBAGIAN','DITOLAK') DEFAULT 'DITERIMA',
  diterima_oleh INT NOT NULL,
  keterangan TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rencana_id) REFERENCES rencana_pengadaan(id) ON DELETE SET NULL,
  FOREIGN KEY (kebutuhan_id) REFERENCES kebutuhan(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- ASET / INVENTARIS (+ QR code)
-- ---------------------------------------------------------------
CREATE TABLE aset (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kode_aset VARCHAR(80) NOT NULL UNIQUE,
  nama VARCHAR(150) NOT NULL,
  kategori VARCHAR(80) NULL,
  merk_type VARCHAR(100) NULL,
  serial_number VARCHAR(100) NULL,
  tanggal_perolehan DATE NULL,
  nilai_perolehan DECIMAL(15,2) DEFAULT 0,
  lokasi_id INT NULL,
  pengguna_id INT NULL,
  unit VARCHAR(100) NULL,
  kondisi ENUM('BAIK','RUSAK RINGAN','RUSAK BERAT') DEFAULT 'BAIK',
  status ENUM('AKTIF','RUSAK','DIPINDAHKAN','DIHAPUS','HILANG') DEFAULT 'AKTIF',
  qr_file VARCHAR(150) NULL,
  sumber VARCHAR(80) NULL,
  keterangan TEXT NULL,
  foto VARCHAR(150) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (lokasi_id) REFERENCES lokasi(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- DISTRIBUSI INVENTARIS
-- ---------------------------------------------------------------
CREATE TABLE distribusi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_distribusi VARCHAR(50) NOT NULL UNIQUE,
  aset_id INT NOT NULL,
  permintaan_oleh INT NOT NULL,
  unit_tujuan VARCHAR(100) NOT NULL,
  lokasi_tujuan INT NULL,
  keperluan VARCHAR(255) NULL,
  status ENUM('MENUNGGU PERSETUJUAN','DISSETUJUI','DISERAHKAN','DITERIMA','DITOLAK') DEFAULT 'MENUNGGU PERSETUJUAN',
  setujui_oleh INT NULL, setujui_tgl DATETIME NULL,
  serah_terima_oleh INT NULL,
  berita_acara TEXT NULL,
  terima_tgl DATETIME NULL,
  catatan VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aset_id) REFERENCES aset(id),
  FOREIGN KEY (lokasi_tujuan) REFERENCES lokasi(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- MUTASI ASET
-- ---------------------------------------------------------------
CREATE TABLE mutasi_aset (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aset_id INT NOT NULL,
  tanggal DATE NOT NULL,
  unit_sebelum VARCHAR(100) NULL, lokasi_sebelum VARCHAR(120) NULL, pengguna_sebelum VARCHAR(120) NULL,
  unit_sesudah VARCHAR(100) NULL,  lokasi_sesudah  VARCHAR(120) NULL, pengguna_sesudah  VARCHAR(120) NULL,
  alasan VARCHAR(255) NULL,
  oleh INT NOT NULL,
  status ENUM('MENUNGGU','SELESAI') DEFAULT 'SELESAI',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aset_id) REFERENCES aset(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- PENGAJUAN PENGHAPUSAN ASET
-- ---------------------------------------------------------------
CREATE TABLE penghapusan_aset (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_penghapusan VARCHAR(50) NOT NULL UNIQUE,
  aset_id INT NOT NULL,
  alasan ENUM('RUSAK BERAT','HILANG','TIDAK EKONOMIS','TIDAK DIPERGUNAKAN','LAINNYA') DEFAULT 'RUSAK BERAT',
  uraian TEXT NULL,
  diajukan_oleh INT NOT NULL, diajukan_tgl DATETIME NULL,
  verifikasi_oleh INT NULL, verifikasi_catatan TEXT NULL, verifikasi_tgl DATETIME NULL,
  persetujuan_oleh INT NULL, persetujuan_catatan TEXT NULL, persetujuan_tgl DATETIME NULL,
  berita_acara_no VARCHAR(100) NULL, berita_acara_tgl DATE NULL,
  status ENUM('PENGAJUAN','VERIFIKASI','PERSETUJUAN','BERITA_ACARA','DIHAPUS','DITOLAK') DEFAULT 'PENGAJUAN',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aset_id) REFERENCES aset(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- KERUSAKAN INVENTARIS
-- ---------------------------------------------------------------
CREATE TABLE kerusakan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_laporan VARCHAR(50) NOT NULL UNIQUE,
  aset_id INT NULL,
  judul VARCHAR(200) NOT NULL,
  deskripsi TEXT NOT NULL,
  foto VARCHAR(150) NULL,
  pelapor INT NOT NULL,
  tanggal_lapor DATE NOT NULL,
  status ENUM('DILAPORKAN','DIVERIFIKASI','DIAGNOSIS','DALAM PERBAIKAN','SELESAI','DITOLAK') DEFAULT 'DILAPORKAN',
  verifikasi_oleh INT NULL, verifikasi_tgl DATETIME NULL,
  diagnosis TEXT NULL, diagnosis_oleh INT NULL, diagnosis_tgl DATETIME NULL,
  selesai_tgl DATETIME NULL, hasil VARCHAR(255) NULL,
  perbaikan_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aset_id) REFERENCES aset(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- PEMELIHARAAN & PERBAIKAN
-- ---------------------------------------------------------------
CREATE TABLE perbaikan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor VARCHAR(50) NOT NULL UNIQUE,
  aset_id INT NULL,
  kerusakan_id INT NULL,
  tanggal DATE NOT NULL,
  item VARCHAR(200) NOT NULL,
  kerusakan_desc VARCHAR(255) NULL,
  vendor VARCHAR(150) NULL,
  teknisi VARCHAR(100) NULL,
  biaya DECIMAL(15,2) DEFAULT 0,
  tindakan TEXT NULL,
  status ENUM('BARU','DALAM PERBAIKAN','SELESAI','GAGAL') DEFAULT 'BARU',
  dicatat_oleh INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aset_id) REFERENCES aset(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- STOCK OPNAME
-- ---------------------------------------------------------------
CREATE TABLE stock_opname (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_opname VARCHAR(50) NOT NULL UNIQUE,
  judul VARCHAR(200) NOT NULL,
  tanggal_mulai DATE NOT NULL,
  tanggal_selesai DATE NULL,
  lokasi_filter VARCHAR(120) NULL,
  unit_filter VARCHAR(100) NULL,
  pelaksana INT NOT NULL,
  status ENUM('BERJALAN','VALIDASI','SELESAI') DEFAULT 'BERJALAN',
  total_data INT DEFAULT 0, total_terverifikasi INT DEFAULT 0,
  total_cocok INT DEFAULT 0, total_tidak_cocok INT DEFAULT 0, total_tidak_ditemukan INT DEFAULT 0,
  catatan TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE opname_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opname_id INT NOT NULL,
  aset_id INT NULL,
  kode_scan VARCHAR(80) NULL,
  hasil ENUM('COCOK','TIDAK COCOK','TIDAK DITEMUKAN') NULL,
  kondisi_hasil VARCHAR(50) NULL,
  catatan VARCHAR(255) NULL,
  scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (opname_id) REFERENCES stock_opname(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- ATK
-- ---------------------------------------------------------------
CREATE TABLE atk_barang (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kode_barang VARCHAR(40) NOT NULL UNIQUE,
  nama VARCHAR(150) NOT NULL,
  kategori VARCHAR(60) NULL,
  satuan VARCHAR(30) DEFAULT 'pcs',
  stok INT DEFAULT 0,
  stok_minimum INT DEFAULT 5,
  harga_satuan DECIMAL(15,2) DEFAULT 0,
  lokasi_simpan VARCHAR(100) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE atk_transaksi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  atk_barang_id INT NOT NULL,
  tipe ENUM('MASUK','KELUAR','SALDO_AWAL') NOT NULL,
  jumlah INT NOT NULL,
  ref_type VARCHAR(40) NULL,
  ref_id INT NULL,
  unit VARCHAR(100) NULL,
  keterangan VARCHAR(255) NULL,
  oleh INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (atk_barang_id) REFERENCES atk_barang(id)
) ENGINE=InnoDB;

CREATE TABLE atk_permintaan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_permintaan VARCHAR(50) NOT NULL UNIQUE,
  unit_pemohon VARCHAR(100) NOT NULL,
  pemohon INT NOT NULL,
  tanggal DATE NOT NULL,
  keperluan VARCHAR(255) NULL,
  status ENUM('MENUNGGU PROSES','STOK TERSEDIA','STOK KOSONG','DISSETUJUI','MENUNGGU PENGAMBILAN','DISERAHKAN','DITOLAK','MASUK RENCANA PENGADAAN') DEFAULT 'MENUNGGU PROSES',
  verifikasi_oleh INT NULL, verifikasi_catatan TEXT NULL, verifikasi_tgl DATETIME NULL,
  distribusi_tgl DATETIME NULL, terima_oleh INT NULL, terima_tgl DATETIME NULL,
  catatan VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE atk_permintaan_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  permintaan_id INT NOT NULL,
  atk_barang_id INT NOT NULL,
  jumlah_minta INT NOT NULL DEFAULT 1,
  jumlah_setuju INT DEFAULT 0,
  FOREIGN KEY (permintaan_id) REFERENCES atk_permintaan(id) ON DELETE CASCADE,
  FOREIGN KEY (atk_barang_id) REFERENCES atk_barang(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- FASILITAS KANTOR (tiket)
-- ---------------------------------------------------------------
CREATE TABLE fasilitas_tiket (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_tiket VARCHAR(50) NOT NULL UNIQUE,
  lokasi_id INT NULL,
  lokasi_text VARCHAR(150) NULL,
  jenis ENUM('AC','MEJA','KURSI','LAMPU','TOILET','RUANGAN','INSTALASI LISTRIK','AIR','PERALATAN UMUM','FASILITAS LAINNYA') DEFAULT 'PERALATAN UMUM',
  masalah TEXT NOT NULL,
  foto VARCHAR(150) NULL,
  prioritas ENUM('RENDAH','NORMAL','TINGGI','SEGERA') DEFAULT 'NORMAL',
  pelapor INT NOT NULL,
  tanggal_lapor DATE NOT NULL,
  status ENUM('LAPORAN BARU','VERIFIKASI','PENUGASAN','PERBAIKAN','PEMERIKSAAN','SELESAI','DITOLAK') DEFAULT 'LAPORAN BARU',
  verifikasi_oleh INT NULL, verifikasi_tgl DATETIME NULL,
  ditugaskan_ke VARCHAR(120) NULL, penugasan_tgl DATETIME NULL,
  perbaikan_catatan TEXT NULL, biaya DECIMAL(15,2) DEFAULT 0, vendor VARCHAR(150) NULL,
  pemeriksaan_catatan VARCHAR(255) NULL, selesai_tgl DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (lokasi_id) REFERENCES lokasi(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- KENDARAAN
-- ---------------------------------------------------------------
CREATE TABLE kendaraan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tnkb VARCHAR(30) NOT NULL UNIQUE,
  merk_type VARCHAR(100) NOT NULL,
  tahun SMALLINT NULL,
  warna VARCHAR(40) NULL,
  nomor_rangka VARCHAR(60) NULL,
  nomor_mesin VARCHAR(60) NULL,
  km_terakhir INT DEFAULT 0,
  kondisi ENUM('BAIK','RUSAK RINGAN','RUSAK BERAT') DEFAULT 'BAIK',
  lokasi VARCHAR(100) NULL,
  penanggung_jawab INT NULL,
  status ENUM('TERSEDIA','DIPINJAM','DIGUNAKAN','SERVICE','RUSAK') DEFAULT 'TERSEDIA',
  jadwal_servis_km INT NULL,
  keterangan VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- PEMINJAMAN KENDARAAN
-- ---------------------------------------------------------------
CREATE TABLE peminjaman_kendaraan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomor_pinjam VARCHAR(50) NOT NULL UNIQUE,
  kendaraan_id INT NOT NULL,
  pemohon INT NOT NULL,
  tanggal_pinjam DATE NOT NULL,
  tanggal_kembali DATE NOT NULL,
  tujuan TEXT NULL,
  pengemudi VARCHAR(100) NULL,
  penumpang VARCHAR(255) NULL,
  km_awal INT NULL, km_akhir INT NULL,
  bbm DECIMAL(15,2) DEFAULT 0,
  bukti VARCHAR(150) NULL,
  kondisi_kembali VARCHAR(255) NULL,
  catatan_kembali VARCHAR(255) NULL,
  status ENUM('MENUNGGU','DISETUJUI','DIGUNAKAN','DIKEMBALIKAN','DITERIMA','DITOLAK') DEFAULT 'MENUNGGU',
  setujui_oleh INT NULL, setujui_tgl DATETIME NULL,
  konfirmasi_tgl DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (kendaraan_id) REFERENCES kendaraan(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- PERAWATAN / SERVIS KENDARAAN
-- ---------------------------------------------------------------
CREATE TABLE perawatan_kendaraan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kendaraan_id INT NOT NULL,
  tanggal DATE NOT NULL,
  jenis VARCHAR(100) NOT NULL,
  km INT DEFAULT 0,
  biaya DECIMAL(15,2) DEFAULT 0,
  vendor VARCHAR(150) NULL,
  catatan TEXT NULL,
  oleh INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (kendaraan_id) REFERENCES kendaraan(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- LOG AKTIVITAS
-- ---------------------------------------------------------------
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  user_name VARCHAR(100) NULL,
  modul VARCHAR(50) NULL,
  aksi VARCHAR(100) NULL,
  detail VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
