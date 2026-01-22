<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt #{{ $sale->invoice_number }}</title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            margin: 0;
            padding: 10px;
            width: 58mm; /* Standard thermal paper width */
        }
        .header {
            text-align: center;
            margin-bottom: 10px;
        }
        .header h2 {
            margin: 0;
            font-size: 16px;
        }
        .header p {
            margin: 2px 0;
            font-size: 10px;
        }
        .divider {
            border-top: 1px dashed #000;
            margin: 5px 0;
        }
        .info {
            margin-bottom: 5px;
        }
        .info div {
            display: flex;
            justify-content: space-between;
        }
        .items {
            width: 100%;
            border-collapse: collapse;
        }
        .items th {
            text-align: left;
            border-bottom: 1px solid #000;
        }
        .items td {
            vertical-align: top;
        }
        .qty {
            width: 15%;
            text-align: center;
        }
        .price {
            width: 25%;
            text-align: right;
        }
        .totals {
            margin-top: 5px;
        }
        .totals div {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        .footer {
            text-align: center;
            margin-top: 15px;
            font-size: 10px;
        }
        @media print {
            body { margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>{{ $sale->branch->name ?? 'Bengkel Maju Jaya' }}</h2>
        <p>{{ $sale->branch->address ?? 'Jl. Raya Bengkel No. 1' }}</p>
        <p>Telp: {{ $sale->branch->phone ?? '-' }}</p>
    </div>

    <div class="divider"></div>

    <div class="info">
        <div><span>No:</span> <span>{{ $sale->invoice_number }}</span></div>
        <div><span>Tgl:</span> <span>{{ $sale->sale_date->format('d/m/Y H:i') }}</span></div>
        <div><span>Kasir:</span> <span>{{ $sale->creator->name ?? '-' }}</span></div>
        <div><span>Pel:</span> <span>{{ $sale->customer->name ?? 'Umum' }}</span></div>
    </div>

    <div class="divider"></div>

    <table class="items">
        @foreach($sale->items as $item)
        <tr>
            <td colspan="3">{{ $item->product->name }}</td>
        </tr>
        <tr>
            <td class="qty">{{ $item->quantity }}x</td>
            <td style="text-align: right;">{{ number_format($item->price, 0, ',', '.') }}</td>
            <td class="price">{{ number_format($item->subtotal, 0, ',', '.') }}</td>
        </tr>
        @endforeach
    </table>

    <div class="divider"></div>

    <div class="totals">
        <div><span>Subtotal:</span> <span>{{ number_format($sale->subtotal, 0, ',', '.') }}</span></div>
        @if($sale->discount_amount > 0)
        <div><span>Diskon:</span> <span>-{{ number_format($sale->discount_amount, 0, ',', '.') }}</span></div>
        @endif
        @if($sale->tax_amount > 0)
        <div><span>Pajak:</span> <span>{{ number_format($sale->tax_amount, 0, ',', '.') }}</span></div>
        @endif
        <div style="font-weight: bold; font-size: 14px; margin-top: 5px;">
            <span>TOTAL:</span> <span>{{ number_format($sale->total_amount, 0, ',', '.') }}</span>
        </div>
        <div><span>Metode Bayar:</span> <span>{{ strtoupper($sale->payment_method) }}</span></div>
    </div>

    <div class="divider"></div>

    <div class="footer">
        <p>Terima Kasih atas kunjungan Anda</p>
        <p>Barang yang sudah dibeli tidak dapat ditukar/dikembalikan</p>
    </div>
    <script>
        window.onload = function() {
            window.print();
            setTimeout(function() { window.close(); }, 500);
        }
    </script>
</body>
</html>
