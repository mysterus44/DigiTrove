<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_product_file_content_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                immutable_column TEXT;
            BEGIN
                immutable_column := CASE
                    WHEN NEW.id IS DISTINCT FROM OLD.id THEN 'id'
                    WHEN NEW.product_id IS DISTINCT FROM OLD.product_id THEN 'product_id'
                    WHEN NEW.storage_disk IS DISTINCT FROM OLD.storage_disk THEN 'storage_disk'
                    WHEN NEW.storage_path IS DISTINCT FROM OLD.storage_path THEN 'storage_path'
                    WHEN NEW.checksum_sha256 IS DISTINCT FROM OLD.checksum_sha256 THEN 'checksum_sha256'
                    WHEN NEW.size_bytes IS DISTINCT FROM OLD.size_bytes THEN 'size_bytes'
                    WHEN NEW.mime_type IS DISTINCT FROM OLD.mime_type THEN 'mime_type'
                    WHEN NEW.version IS DISTINCT FROM OLD.version THEN 'version'
                    WHEN NEW.created_at IS DISTINCT FROM OLD.created_at THEN 'created_at'
                    ELSE NULL
                END;

                IF immutable_column IS NULL THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'product_files content is immutable; a new content version requires a new row',
                    DETAIL = 'Immutable column: ' || immutable_column;
            END;
            $$;

            CREATE TRIGGER product_files_enforce_content_immutability_trigger
            BEFORE UPDATE ON product_files
            FOR EACH ROW
            EXECUTE FUNCTION enforce_product_file_content_immutability();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS product_files_enforce_content_immutability_trigger ON product_files');
        DB::statement('DROP FUNCTION IF EXISTS enforce_product_file_content_immutability()');
    }
};
