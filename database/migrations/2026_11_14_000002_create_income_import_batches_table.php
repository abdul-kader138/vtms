<?php

use App\Services\Tax\Import\Support\IncomeRow;
use App\Support\Tax\InvoiceDocumentType;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per imported file (who, when, how, what came of it) so an import
 * can be reviewed and undone as a unit — VTMS grouped rows by a "transfer"
 * reference for the same reason. Income rows also get:
 *  - document_type: TD code (TD01 invoice, TD04 credit note, …) — credit
 *    notes are stored as negative amounts;
 *  - fingerprint: a format-independent business key (seller VAT + credit
 *    flag + number + date, or the device "Id invio") so the same invoice
 *    imported once from the CSV list and once from its XML is caught.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('format', 30);
            $table->string('channel', 20)->default('upload');
            $table->string('original_filename');
            $table->string('file_hash', 64)->index();
            $table->string('stored_path')->nullable();
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('credit_notes')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->json('recalculated_years')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });

        Schema::table('income_data', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('imported_by')
                ->constrained('income_import_batches')->nullOnDelete();
            $table->string('document_type', 40)->nullable()->after('source');
            $table->string('fingerprint', 64)->nullable()->after('external_id');
            $table->unique(['customer_id', 'fingerprint']);
        });

        $this->backfillFingerprints();
    }

    public function down(): void
    {
        Schema::table('income_data', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'fingerprint']);
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn(['document_type', 'fingerprint']);
        });

        Schema::dropIfExists('income_import_batches');
    }

    /**
     * Same keys the importer builds, for rows imported before this migration.
     * Existing credit notes are only tagged, not sign-flipped: changing
     * stored amounts would silently change past calculations — the import
     * screen flags them instead (see IncomeImportService::legacyCreditNotes).
     */
    private function backfillFingerprints(): void
    {
        $seen = [];

        DB::table('income_data')->orderBy('id')->chunkById(500, function ($rows) use (&$seen) {
            foreach ($rows as $row) {
                $meta = json_decode((string) $row->meta, true) ?: [];

                if ($row->source === 'privati_device') {
                    $hash = IncomeRow::deviceFingerprint($row->external_id);
                    $type = null;
                } else {
                    $type = InvoiceDocumentType::normalize($meta['tipo_documento'] ?? null);
                    $hash = IncomeRow::invoiceFingerprint(
                        $meta['partita_iva_fornitore'] ?? null,
                        InvoiceDocumentType::isCreditNote($type),
                        $meta['numero_fattura'] ?? $row->reference_no,
                        Carbon::parse($row->transaction_date),
                    );
                }

                if ($type !== null) {
                    DB::table('income_data')->where('id', $row->id)->update(['document_type' => $type]);
                }

                if ($hash === null) {
                    continue;
                }

                // Pre-existing duplicates keep a null fingerprint rather than
                // failing the migration on the new unique index.
                if (isset($seen[$row->customer_id][$hash])) {
                    continue;
                }
                $seen[$row->customer_id][$hash] = true;

                DB::table('income_data')->where('id', $row->id)->update(['fingerprint' => $hash]);
            }
        });
    }
};
