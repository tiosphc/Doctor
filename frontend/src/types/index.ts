export type UserRole = "admin" | "customer" | "receptionist" | "doctor";

export type BlogAuthor = {
    id: number;
    name: string;
};

export type Blog = {
    id: number;
    title: string;
    slug: string;
    category: string;
    category_id?: number | null;
    excerpt: string;
    image: string | null;
    published_at: string;
    author: BlogAuthor | null;
    content?: string;
};

export type BlogCategory = {
    id: number;
    name: string;
    slug: string;
    blogs_count: number;
};

export type User = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: UserRole;
};

export type CanonicalCustomer = {
    id: number;
    customer_code: string | null;
    user_id: number | null;
    name: string;
    email: string | null;
    phone: string | null;
    role: "customer";
    status: "active" | "inactive" | "merged";
};

export type Service = {
    id: number;
    slug: string;
    name: string;
    category?: string | null;
    category_slug?: string | null;
    category_id?: number | null;
    description: string | null;
    duration: number;
    price: string;
    image: string | null;
    introduction?: string | null;
    short_description?: string | null;
    duration_note?: string | null;
    price_note?: string | null;
    hero_image?: string | null;
    hero_disclaimer?: string | null;
    cta_label?: string | null;
    sort_order?: number;
    seo_title?: string | null;
    seo_description?: string | null;
    content?: ServiceContent | null;
    content_image_urls?: Record<string, string>;
    result_image_urls?: Record<string, ServiceContentResultCase>;
    status?: "active" | "inactive";
    average_rating?: number | null;
    review_count?: number;
};

export type ServiceContentBlockType =
    "overview" | "process" | "text" | "image_text" | "info_grid" | "faq" | "callout" | "cta";

export type ServiceContentCard = {
    title?: string | null;
    text?: string | null;
};

export type ServiceContentStep = {
    title?: string | null;
    description?: string | null;
    /** @deprecated Legacy block content used `text`; fixed content uses `description`. */
    text?: string | null;
};

export type ServiceContentInfoItem = {
    title?: string | null;
    text?: string | null;
};

export type ServiceContentFaqItem = {
    question?: string | null;
    answer?: string | null;
};

export type ServiceContentBenefit = {
    title?: string | null;
    description?: string | null;
};

export type ServiceContentResultCase = {
    before_image?: string | null;
    after_image?: string | null;
    caption?: string | null;
};

export type ServiceContentBenefits = {
    title?: string | null;
    description?: string | null;
    items?: ServiceContentBenefit[];
};

export type ServiceContentProcess = {
    title?: string | null;
    description?: string | null;
    steps?: ServiceContentStep[];
};

export type ServiceContentResults = {
    title?: string | null;
    description?: string | null;
    cases?: ServiceContentResultCase[];
    disclaimer?: string | null;
};

export type ServiceContentFaq = {
    title?: string | null;
    items?: ServiceContentFaqItem[];
};

type ServiceContentBlockBase = {
    id: string;
    eyebrow?: string | null;
    heading?: string | null;
    body?: string | null;
};

export type ServiceContentOverviewBlock = ServiceContentBlockBase & {
    type: "overview";
    expect_title?: string | null;
    expect_items?: string[];
    cards?: ServiceContentCard[];
};

export type ServiceContentProcessBlock = ServiceContentBlockBase & {
    type: "process";
    description?: string | null;
    steps?: ServiceContentStep[];
};

export type ServiceContentTextBlock = ServiceContentBlockBase & {
    type: "text";
};

export type ServiceContentImageTextBlock = ServiceContentBlockBase & {
    type: "image_text";
    image?: string | null;
    image_position?: "left" | "right" | null;
};

export type ServiceContentInfoGridBlock = ServiceContentBlockBase & {
    type: "info_grid";
    items?: ServiceContentInfoItem[];
};

export type ServiceContentFaqBlock = ServiceContentBlockBase & {
    type: "faq";
    items?: ServiceContentFaqItem[];
};

export type ServiceContentCalloutBlock = ServiceContentBlockBase & {
    type: "callout";
};

export type ServiceContentCtaBlock = ServiceContentBlockBase & {
    type: "cta";
    heading?: string | null;
    description?: string | null;
    button_label?: string | null;
    button_link?: string | null;
};

export type ServiceContentBlock =
    | ServiceContentOverviewBlock
    | ServiceContentProcessBlock
    | ServiceContentTextBlock
    | ServiceContentImageTextBlock
    | ServiceContentInfoGridBlock
    | ServiceContentFaqBlock
    | ServiceContentCalloutBlock
    | ServiceContentCtaBlock;

export type ServiceContent = {
    benefits?: ServiceContentBenefits;
    process?: ServiceContentProcess;
    results?: ServiceContentResults;
    faq?: ServiceContentFaq;
    /** @deprecated Kept for reading and normalizing content created by the old block editor. */
    blocks?: ServiceContentBlock[];
};

export type ServiceCategory = {
    id: number;
    name: string;
    slug: string;
    short_description?: string | null;
    hero_image?: string | null;
    services_count: number;
    services?: Service[];
};

export type ServiceCategoryExplorer = ServiceCategory & {
    short_description: string | null;
    hero_image: string | null;
    services: Service[];
};

export type Doctor = {
    id: number;
    user_id?: number | null;
    name: string;
    specialty: string;
    bio: string | null;
    phone?: string | null;
    email?: string | null;
    avatar: string | null;
    status?: "active" | "inactive";
    account_status?: "legacy_unlinked" | "pending_setup" | "active" | "suspended";
    must_change_password?: boolean | null;
    configuration_status?: "missing_services" | "missing_schedule" | "ready" | "inactive";
    is_booking_ready?: boolean;
    services_count?: number;
    schedules_count?: number;
    services?: Service[];
    average_rating?: number | null;
    review_count?: number;
    rating_distribution?: Record<string, number> | null;
};

export type RatingSummary = {
    average_rating: number | null;
    review_count: number;
    rating_distribution?: Record<string, number>;
};

export type Review = {
    id: number;
    appointment_id?: number;
    rating: number;
    comment: string | null;
    status?: "published" | "hidden";
    verified: true;
    reviewer: { name: string };
    doctor?: Pick<Doctor, "id" | "name">;
    service?: Pick<Service, "id" | "name">;
    created_at: string;
    updated_at: string;
};

export type VoucherStatus = "active" | "used" | "expired" | "revoked";

export type Voucher = {
    id: number;
    code: string;
    type: "percentage";
    value: string;
    source: "review_reward" | "loyalty_milestone" | "admin";
    source_id: number;
    milestone: number | null;
    status: VoucherStatus;
    expires_at: string;
    used_at: string | null;
    created_at: string;
    customer?: Pick<User, "id" | "name" | "email"> | null;
    used_appointment_id?: number | null;
};

export type LoyaltyMilestone = {
    visits: number;
    reward_type: "percentage";
    reward_value: number;
};

export type LoyaltySummary = {
    completed_visits: number;
    next_milestone: (LoyaltyMilestone & { remaining_visits: number }) | null;
    achieved_milestones: LoyaltyMilestone[];
};

export type AdminCustomerSummary = CanonicalCustomer & {
    completed_visits: number;
    last_appointment_date: string | null;
};

export type AdminCustomerDetail = CanonicalCustomer & {
    loyalty: LoyaltySummary;
    statistics: {
        total_appointments: number;
        completed_appointments: number;
        upcoming_appointments: number;
        cancelled_appointments: number;
        reviews: number;
        available_vouchers: number;
    };
    loyalty_vouchers: Voucher[];
};

export type BookingPricing = {
    original_price: string | null;
    discount_amount: string;
    final_price: string | null;
};

export type AppointmentStatus =
    | "pending"
    | "confirmed"
    | "checked_in"
    | "in_progress"
    | "treatment_done"
    | "completed"
    | "cancelled"
    | "no_show";

export type NotificationEvent =
    | "appointment_created"
    | "appointment_confirmed"
    | "appointment_rescheduled"
    | "appointment_cancelled"
    | "appointment_reminder"
    | "appointment_checked_in"
    | "appointment_treatment_done"
    | "appointment_completed"
    | "review_invitation"
    | "review_reward_issued"
    | "loyalty_milestone_reward";

export type CustomerNotification = {
    id: string;
    event: NotificationEvent;
    title: string;
    message: string;
    appointment_id: number | null;
    booking_code?: string | null;
    action_url: string | null;
    status: string | null;
    source?: string | null;
    actor_role?: UserRole | null;
    audience?: "customer" | "doctor" | "admin" | "staff" | "operations" | null;
    data?: {
        customer_name?: string | null;
        service_name?: string | null;
        doctor_name?: string | null;
        appointment_date?: string | null;
        start_time?: string | null;
        end_time?: string | null;
        source?: string | null;
        actor_role?: UserRole | null;
        voucher_id?: number | null;
        milestone?: number | null;
        reward_value?: number | null;
        old_appointment_date?: string | null;
        old_start_time?: string | null;
        old_end_time?: string | null;
        new_appointment_date?: string | null;
        new_start_time?: string | null;
        new_end_time?: string | null;
    } | null;
    created_at: string;
    read_at: string | null;
};

export type Appointment = {
    id: number;
    customer_type: "guest" | "registered";
    customer?: User | null;
    guest?: { name: string; email?: string; phone?: string } | null;
    booking_code?: string;
    doctor: Doctor;
    service: Service;
    review?: Review | null;
    voucher?: Voucher | null;
    pricing: BookingPricing;
    appointment_date: string;
    start_time: string;
    end_time: string;
    status: AppointmentStatus;
    can_reschedule: boolean;
    reschedule_block_reason?: string | null;
    can_cancel: boolean;
    cancel_block_reason?: string | null;
    minimum_change_notice_hours: number;
    reschedule_limit: number;
    reschedules_remaining: number;
    has_exhausted_reschedules: boolean;
    rescheduled_at?: string | null;
    reschedule_count?: number | null;
    was_rescheduled?: boolean;
    original_appointment_date?: string | null;
    original_start_time?: string | null;
    original_end_time?: string | null;
    note: string | null;
    created_at: string;
};

export type PublicAppointment = {
    booking_code: string;
    customer_name: string;
    doctor: { name: string };
    service: { name: string; duration: number };
    appointment_date: string;
    start_time: string;
    end_time: string;
    status: AppointmentStatus;
    can_reschedule: boolean;
    reschedule_block_reason: string | null;
    can_cancel: boolean;
    cancel_block_reason: string | null;
    minimum_change_notice_hours: number;
    reschedules_remaining: number;
    was_rescheduled: boolean;
};

export type StaffUser = User & {
    role: "receptionist" | "doctor";
    doctor?: Doctor | null;
};

export type AppointmentAction =
    "confirm" | "check-in" | "no-show" | "cancel" | "start" | "complete" | "complete-treatment";

export type StaffDashboard = {
    today: number;
    pending: number;
    confirmed: number;
    checked_in: number;
    in_progress: number;
    treatment_done: number;
    completed: number;
    cancelled: number;
    no_show: number;
};

export type DoctorSchedule = {
    id: number;
    doctor_id: number;
    day_of_week: number;
    day_name: string;
    start_time: string;
    end_time: string;
};

export type DoctorTimeOff = {
    id: number;
    doctor_id: number;
    date: string;
    start_time: string | null;
    end_time: string | null;
    reason: string | null;
    full_day: boolean;
};

export type PaginatedResponse<T> = {
    data: T[];
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        path: string;
        per_page: number;
        to: number | null;
        total: number;
    };
};

export type ResourceResponse<T> = { data: T; message?: string };
export type ValidationErrors = Record<string, string[]>;

export type AvailableSlots = {
    doctor_id: number;
    service_id: number;
    date: string;
    duration: number;
    slots: string[];
};

export type BookingDraft = {
    serviceId: number | null;
    doctorId: number | null;
    date: string;
    time: string;
    customer: { name: string; phone: string; email: string; note: string };
};
