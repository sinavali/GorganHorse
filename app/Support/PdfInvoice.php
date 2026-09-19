<?php
/**
 * Minimal FPDF-compatible wrapper using vendored FPDF.
 */
require_once __DIR__ . '/vendor/fpdf/fpdf.php';

class PdfInvoice extends \FPDF
{
    public function header(): void
    {
        $this->setFont('Helvetica', 'B', 14);
        $this->cell(0, 8, 'هيئت سوارکاری استان گلستان', 0, 1, 'C');
        $this->setFont('Helvetica', '', 8);
        $this->cell(0, 5, 'صندوق پرداخت', 0, 1, 'C');
        $this->line(10, $this->getY() + 2, 200, $this->getY() + 2);
        $this->ln(6);
    }

    public function footer(): void
    {
        $this->setY(-15);
        $this->setFont('Helvetica', 'I', 8);
        $this->cell(0, 10, 'صفحه ' . $this->pageNo() . '/{nb}', 0, 0, 'C');
    }

    public function invoice(array $order, array $rider = [], array $competition = [], string $federation = 'هيئت سوارکاری استان گلستان'): void
    {
        $this->addPage();
        $this->setFont('Helvetica', 'B', 12);
        $this->cell(0, 8, 'فاتوره صورت حساب', 0, 1, 'L');
        $this->ln(4);

        $this->setFont('Helvetica', 'B', 10);
        $this->cell(40, 6, 'شماره صورت‌حساب:', 0, 0);
        $this->setFont('Helvetica', '', 10);
        $this->cell(0, 6, (string) ($order['id'] ?? ''), 0, 1);

        $this->setFont('Helvetica', 'B', 10);
        $this->cell(40, 6, 'شماره مرجع:', 0, 0);
        $this->setFont('Helvetica', '', 10);
        $this->cell(0, 6, (string) ($order['authority'] ?? ''), 0, 1);

        $this->setFont('Helvetica', 'B', 10);
        $this->cell(40, 6, 'شماره پیگیری:', 0, 0);
        $this->setFont('Helvetica', '', 10);
        $this->cell(0, 6, (string) ($order['ref_id'] ?? ''), 0, 1);

        $this->setFont('Helvetica', 'B', 10);
        $this->cell(40, 6, 'مبلغ (تومان):', 0, 0);
        $this->setFont('Helvetica', '', 10);
        $amount = (int) ($order['amount_irt'] ?? 0);
        $this->cell(0, 6, number_format($amount, 0, '.', ',') . ' تومان', 0, 1);

        $this->setFont('Helvetica', 'B', 10);
        $this->cell(40, 6, 'تاریخ پرداخت:', 0, 0);
        $this->setFont('Helvetica', '', 10);
        $this->cell(0, 6, (string) ($order['paid_at'] ?? $order['created_at'] ?? ''), 0, 1);

        $this->setFont('Helvetica', 'B', 10);
        $this->cell(40, 6, 'وضعیت:', 0, 0);
        $this->setFont('Helvetica', '', 10);
        $statusMap = ['paid' => 'پرداخت شده', 'pending' => 'در انتظار', 'pending_refund' => 'در انتظار استرداد', 'refunded' => 'استرداد شده', 'failed' => 'ناموفق'];
        $this->cell(0, 6, $statusMap[$order['status']] ?? $order['status'] ?? '', 0, 1);

        $this->ln(6);
        $this->setFont('Helvetica', 'B', 10);
        $this->cell(0, 6, 'اطلاعات سوارکار:', 0, 1);
        $this->setFont('Helvetica', '', 10);
        $this->cell(0, 6, ($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? '') . ' - ' . ($rider['phone'] ?? ''), 0, 1);

        $this->ln(4);
        $this->setFont('Helvetica', 'B', 10);
        $this->cell(0, 6, 'اطلاعات مسابقه:', 0, 1);
        $this->setFont('Helvetica', '', 10);
        $this->cell(0, 6, (string) ($competition['title'] ?? ''), 0, 1);

        $this->ln(10);
        $this->setFont('Helvetica', 'B', 9);
        $this->cell(0, 6, $federation, 0, 1, 'C');
    }
}