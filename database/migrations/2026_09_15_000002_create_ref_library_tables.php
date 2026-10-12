<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tabel milik FastAPI Reference Library (PLAN_MYSQL_BACKED_REFERENCE_STORAGE §3).
 * Definisi SQL 1 sumber dengan plan doc — jangan ubah kolom di sini tanpa update plan.
 *
 * ref_library : metadata + teks OCR (TANPA blob → list tetap cepat).
 * ref_blobs   : gambar + thumbnail (MEDIUMBLOB/BLOB, cascade saat ref dihapus).
 */
class CreateRefLibraryTables extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS ref_library (
              ref_id               VARCHAR(64)  NOT NULL PRIMARY KEY,
              name                 VARCHAR(80)  NOT NULL,
              template_id          VARCHAR(32)  NOT NULL DEFAULT '',
              source               VARCHAR(64)  NOT NULL DEFAULT '',
              user_ref             VARCHAR(64)  NOT NULL DEFAULT '',
              sha256               CHAR(64)     NOT NULL,
              text_hash            CHAR(64)     NOT NULL DEFAULT '',
              cleaned_text         MEDIUMTEXT   NULL,
              lines_json           MEDIUMTEXT   NOT NULL,
              engine               VARCHAR(32)  NOT NULL DEFAULT '',
              ocr_status           VARCHAR(20)  NOT NULL DEFAULT 'pending',
              is_empty             TINYINT(1)   NOT NULL DEFAULT 0,
              error                VARCHAR(500) NOT NULL DEFAULT '',
              file_size_bytes      INT UNSIGNED NOT NULL DEFAULT 0,
              processing_time_seconds FLOAT     NOT NULL DEFAULT 0,
              usage_count          INT UNSIGNED NOT NULL DEFAULT 0,
              created_at           VARCHAR(32)  NOT NULL,
              updated_at           VARCHAR(32)  NOT NULL,
              last_used_at         VARCHAR(32)  NOT NULL DEFAULT '',
              UNIQUE KEY uq_ref_library_sha256 (sha256),
              KEY idx_ref_library_template (template_id),
              KEY idx_ref_library_created (created_at DESC),
              KEY idx_ref_library_used (last_used_at DESC),
              KEY idx_ref_library_text_hash (text_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS ref_blobs (
              ref_id  VARCHAR(64) NOT NULL PRIMARY KEY,
              image   MEDIUMBLOB  NOT NULL,
              thumb   BLOB        NOT NULL,
              CONSTRAINT fk_ref_blobs_ref FOREIGN KEY (ref_id)
                REFERENCES ref_library (ref_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        DB::statement("DROP TABLE IF EXISTS ref_blobs");
        DB::statement("DROP TABLE IF EXISTS ref_library");
    }
}
