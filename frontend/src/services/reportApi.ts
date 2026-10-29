import { apiRequest } from "./api";

export type ReportFilters = {
    from?: string;
    to?: string;
    channel?: "all" | "retail" | "dealer";
    warehouse_id?: number;
    supplier_id?: number;
    dealer_id?: number;
};
export type ReportPeriod = { from: string; to: string; timezone: string; channel: string };
export type MoneyTotals = { gross: string; refunds: string; net: string; currency: string };
export type ReportTrendPoint = { date: string; gross: string; refunds: string; net: string };
export type ReportCount = { status: string; count: number };
export type ReportSku = {
    sku_id: number;
    sku: string;
    product_name: string;
    fulfilled_quantity: string;
};
export type ReportOverview = {
    period: ReportPeriod;
    sales: MoneyTotals;
    channels: { retail: MoneyTotals; dealer: MoneyTotals };
    orders: {
        created_count: number;
        by_status: ReportCount[];
        by_channel: { channel: string; count: number }[];
    };
    trend: ReportTrendPoint[];
    top_products: ReportSku[];
    top_dealers: ReportDetails["dealers"]["top_dealers"];
    inventory: {
        on_hand: string;
        reserved: string;
        available: string;
        low_stock_rows: number;
        out_of_stock_rows: number;
    };
    procurement: {
        purchase_order_count: number;
        completed_receipt_value: string;
        net_received_value: string;
    };
    promotions: ReportDetails["promotions"];
    wallets: ReportDetails["wallets"];
};
export type ReportDetails = {
    sales: {
        period: ReportPeriod;
        totals: MoneyTotals;
        channels: ReportOverview["channels"];
        trend: ReportTrendPoint[];
    };
    orders: {
        period: ReportPeriod;
        summary: ReportOverview["orders"];
        recent: {
            id: number;
            order_code: string;
            sales_channel: string;
            order_status: string;
            payment_status: string;
            grand_total: string;
            created_at: string;
        }[];
    };
    products: {
        period: ReportPeriod;
        ranking_metric: string;
        top_skus: ReportSku[];
        completed_return_quantity: string;
    };
    inventory: {
        period: ReportPeriod;
        snapshot_at: string;
        summary: ReportOverview["inventory"];
        warehouses: {
            warehouse_id: number;
            code: string;
            name: string;
            on_hand: string;
            reserved: string;
            available: string;
        }[];
        movements: { type: string; count: number; signed_quantity: string }[];
    };
    procurement: {
        period: ReportPeriod;
        summary: ReportOverview["procurement"] & {
            non_cancelled_ordered_value: string;
            purchase_return_value: string;
            by_status: ReportCount[];
        };
        top_suppliers: {
            supplier_id: number;
            code: string;
            name: string;
            received_value: string;
            return_value: string;
            net_received_value: string;
        }[];
    };
    dealers: {
        period: ReportPeriod;
        totals: MoneyTotals;
        top_dealers: {
            dealer_id: number;
            code: string;
            name: string;
            gross: string;
            refunds: string;
            net: string;
            base_tier: string | null;
            effective_tier: string | null;
            has_active_override: boolean;
        }[];
        base_tier_distribution: { base_tier: string; dealer_count: number }[];
    };
    wallets: {
        period: ReportPeriod;
        current_balance: string;
        wallet_count: number;
        completed_deposits: { count: number; amount: string };
        paid_payos_topups: { count: number; amount: string };
        flows: { type: string; count: number; amount: string }[];
    };
    promotions: {
        period: ReportPeriod;
        redemptions_by_current_status: {
            status: string;
            count: number;
            discount_snapshot: string;
        }[];
        redeemed_discount_snapshot: string;
        released_in_period: { count: number; discount_snapshot: string };
    };
    "clinic-summary": {
        period: ReportPeriod;
        appointment_count: number;
        by_status: { status: string; count: number }[];
    };
};
export type ReportName = keyof ReportDetails;

export const reportApi = {
    overview: (filters: ReportFilters = {}) =>
        apiRequest<ReportOverview>("/api/admin/reports/overview", { query: filters }),
    detail: <Name extends ReportName>(name: Name, filters: ReportFilters = {}) =>
        apiRequest<ReportDetails[Name]>(`/api/admin/reports/${name}`, { query: filters }),
};
