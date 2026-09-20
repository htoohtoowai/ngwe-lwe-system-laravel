<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\AuditLogService;
use App\Services\DailyReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AdminBranchReportController extends Controller
{
    public function __construct(
        private readonly DailyReportService $reports,
        private readonly AuditLogService $audit,
    ) {}

    public function closeDay(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query->where('is_active', true),
                ),
            ],
            'date' => ['required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $branch = Branch::query()->findOrFail((int) $data['branch_id']);

        $this->reports->close(
            $request->user(),
            (string) $data['date'],
            $data['notes'] ?? null,
            (int) $branch->id,
        );

        $this->audit->record(
            action: 'close_day',
            category: 'system',
            module: 'daily_closing',
            entityType: 'daily_summary',
            description: 'Closed branch business day',
            details: [
                'branch_id' => (int) $branch->id,
                'branch_code' => $branch->code,
                'branch_name' => $branch->name,
                'date' => (string) $data['date'],
                'notes' => $data['notes'] ?? null,
            ],
        );

        return back()->with(
            'success',
            "{$branch->name} business day closed.",
        );
    }

    public function pdf(Request $request): Response
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'date' => ['required', 'date'],
        ]);

        $branch = Branch::query()->findOrFail((int) $data['branch_id']);
        $summary = $this->reports->summary(
            (string) $data['date'],
            (int) $branch->id,
        );

        $lines = [
            'Ngwe Lwe Branch Daily Report',
            'Branch: '.$branch->name.' ('.$branch->code.')',
            'Date: '.$summary['summary_date'],
            'Cash In: '.$summary['total_cash_in'],
            'Cash Out: '.$summary['total_cash_out'],
            'Send Money: '.$summary['total_send_money'],
            'Receive Money: '.$summary['total_receive_money'],
            'Transfer: '.$summary['total_transfer'],
            'Exchange: '.$summary['total_exchange'],
            'Commission: '.$summary['total_commission'],
            'Customer Fees: '.$summary['total_customer_fees'],
            'Total Profit: '.$summary['total_profit'],
            'Transactions: '.$summary['transaction_count'],
            'Main Vault: '.$summary['main_vault_total'],
            'Employee Floats: '.$summary['employee_floats_total'],
            'Branch Cash: '.$summary['total_cash'],
            'Branch Digital: '.$summary['total_digital'],
            'Shared Digital (excluded): '.$summary['shared_global_digital_total'],
            'Branch Grand Total: '.$summary['grand_total'],
        ];

        $this->audit->record(
            action: 'report_export',
            category: 'system',
            module: 'reports',
            entityType: 'daily_report',
            description: 'Exported branch daily report PDF',
            details: [
                'branch_id' => (int) $branch->id,
                'branch_code' => $branch->code,
                'report_date' => $summary['summary_date'],
            ],
        );

        $filename = sprintf(
            'daily-report-%s-%s.pdf',
            strtolower($branch->code),
            $summary['summary_date'],
        );

        return response($this->minimalPdf($lines), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function minimalPdf(array $lines): string
    {
        $escape = fn (string $value): string => str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $value,
        );

        $content = "BT\n/F1 16 Tf\n50 790 Td\n";

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -24 Td\n";
            }

            $content .= '('.$escape($line).") Tj\n";
        }

        $content .= "ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}endstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1);
        $pdf .= " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
