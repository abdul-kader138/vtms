<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the F24 form needs beyond amounts and codes:
 *  - codice_fiscale: the payer's tax code (F24 header);
 *  - inps_office_code + inps_code: "codice sede" and the 17-digit "codice
 *    INPS" from the INPS payment notice (INPS section, Artigiani/Commercianti);
 *  - chamber_province: "codice ente" for Diritto Annuale (e.g. FI, MI);
 *  - gs_other_pension: a Gestione Separata professional who already has a
 *    pension or other compulsory cover pays under P10 instead of PXX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('codice_fiscale', 16)->nullable()->after('vat_no');
            $table->string('inps_office_code', 4)->nullable()->after('inps_discount_eligible');
            $table->string('inps_code', 17)->nullable()->after('inps_office_code');
            $table->boolean('gs_other_pension')->default(false)->after('inps_code');
            $table->string('chamber_province', 2)->nullable()->after('chamber_surcharge');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['codice_fiscale', 'inps_office_code', 'inps_code', 'gs_other_pension', 'chamber_province']);
        });
    }
};
