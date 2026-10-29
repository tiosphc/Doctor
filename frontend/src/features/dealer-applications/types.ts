export type DealerApplicationStatus = "pending" | "approved" | "rejected" | "cancelled";

export type DealerApplication = {
    id: number;
    company_name: string;
    trading_name: string | null;
    contact_name: string;
    email: string;
    phone: string;
    tax_code: string | null;
    business_address_line1: string;
    business_address_line2: string | null;
    city: string;
    province: string;
    country: string;
    postal_code: string | null;
    business_type: string | null;
    estimated_monthly_purchase: string | null;
    note: string | null;
    status: DealerApplicationStatus;
    submitted_at: string;
    reviewed_at: string | null;
    rejection_reason: string | null;
    applicant?: { id: number; name: string; email: string };
    reviewer?: { id: number; name: string } | null;
    approved_account?: { id: number; code: string } | null;
};

export type DealerApplicationInput = Pick<
    DealerApplication,
    | "company_name"
    | "contact_name"
    | "email"
    | "phone"
    | "business_address_line1"
    | "city"
    | "province"
    | "country"
> &
    Partial<
        Pick<
            DealerApplication,
            | "trading_name"
            | "tax_code"
            | "business_address_line2"
            | "postal_code"
            | "business_type"
            | "estimated_monthly_purchase"
            | "note"
        >
    >;
