<?php

namespace App\Support;

class SupplierBalance
{
    // The outer supplier query uses alias s. Payments settle the supplier account,
    // not individual purchases; original purchase credit must stay unchanged.
    public static function sql(): string
    {
        return "CAST(COALESCE((SELECT SUM(p.credit_amount) FROM purchases p WHERE p.supplier_id=s.id AND p.status='completed'),0)-COALESCE((SELECT SUM(CASE WHEN sp.direction='shop_to_supplier' THEN sp.amount ELSE -sp.amount END) FROM supplier_payments sp WHERE sp.supplier_id=s.id AND sp.status='completed'),0) AS SIGNED)";
    }
}
