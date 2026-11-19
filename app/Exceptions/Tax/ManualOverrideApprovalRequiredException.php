<?php

namespace App\Exceptions\Tax;

use RuntimeException;

/**
 * Thrown by TaxCalculationOrchestrator::commit() when a submitted override
 * actually differs from the computed figure but the current user lacks
 * finalize_tax_calculation — distinct from a plain RuntimeException (missing
 * reason, locked year) so CustomerTaxDashboard can queue the submission as a
 * TaxAdjustmentRequest instead of just reporting failure.
 */
class ManualOverrideApprovalRequiredException extends RuntimeException {}
