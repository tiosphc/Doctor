import type { ValidationErrors } from "@/types";

const apiUrl = (import.meta.env["VITE_API_URL"] || "http://localhost:8000").replace(/\/$/, "");
let csrfRequest: Promise<void> | null = null;

type RequestOptions = Omit<RequestInit, "body"> & {
    body?: unknown;
    query?: Record<string, string | number | boolean | null | undefined>;
};

type ErrorPayload = {
    message?: string;
    errors?: ValidationErrors;
    code?: string;
    sku?: string;
    requested?: string;
    available?: string;
    warehouse_id?: number;
};

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: ValidationErrors = {},
        public readonly retryAfter: number | null = null,
        public readonly code: string | null = null,
        public readonly details: {
            sku?: string | undefined;
            requested?: string | undefined;
            available?: string | undefined;
            warehouse_id?: number | undefined;
        } = {},
    ) {
        super(message);
        this.name = "ApiError";
    }
}

function buildUrl(path: string, query?: RequestOptions["query"]): string {
    const url = new URL(path, `${apiUrl}/`);
    Object.entries(query ?? {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== "") {
            url.searchParams.set(
                key,
                typeof value === "boolean" ? (value ? "1" : "0") : String(value),
            );
        }
    });
    return url.toString();
}

function xsrfToken(): string | null {
    if (typeof document === "undefined") return null;
    const cookie = document.cookie.split("; ").find((item) => item.startsWith("XSRF-TOKEN="));
    return cookie ? decodeURIComponent(cookie.split("=").slice(1).join("=")) : null;
}

async function ensureCsrfCookie(force = false): Promise<void> {
    if (!force && xsrfToken()) return;
    csrfRequest ??= fetch(buildUrl("/sanctum/csrf-cookie"), {
        credentials: "include",
        headers: { Accept: "application/json" },
    }).then(async (response) => {
        if (!response.ok) throw await parseError(response);
    });

    try {
        await csrfRequest;
    } finally {
        csrfRequest = null;
    }
}

async function parseError(response: Response): Promise<ApiError> {
    let payload: ErrorPayload = {};
    try {
        payload = (await response.json()) as ErrorPayload;
    } catch {
        payload = {};
    }

    const defaults: Record<number, string> = {
        401: "Phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại.",
        403: "Bạn không có quyền thực hiện thao tác này.",
        404: "Không tìm thấy dữ liệu yêu cầu.",
        409: "Dữ liệu vừa thay đổi. Vui lòng kiểm tra và thử lại.",
        419: "Phiên bảo mật đã hết hạn. Vui lòng thử lại.",
        422: "Thông tin chưa hợp lệ. Vui lòng kiểm tra lại.",
        429: "Bạn thao tác quá nhanh. Vui lòng thử lại sau.",
        500: "Hệ thống đang gặp sự cố. Vui lòng thử lại sau.",
    };
    const retryHeader = response.headers.get("Retry-After");
    return new ApiError(
        response.status,
        response.status === 429
            ? (defaults[429] ?? "Bạn thao tác quá nhanh. Vui lòng thử lại sau.")
            : payload.message || defaults[response.status] || "Không thể kết nối máy chủ.",
        payload.errors ?? {},
        retryHeader ? Number(retryHeader) : null,
        payload.code ?? null,
        {
            sku: payload.sku,
            requested: payload.requested,
            available: payload.available,
            warehouse_id: payload.warehouse_id,
        },
    );
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const { body, query, headers, ...init } = options;
    const method = (init.method ?? "GET").toUpperCase();
    if (!["GET", "HEAD", "OPTIONS"].includes(method) && path !== "/sanctum/csrf-cookie") {
        await ensureCsrfCookie();
    }
    const usesCsrf = !["GET", "HEAD", "OPTIONS"].includes(method);
    const token = usesCsrf ? xsrfToken() : null;
    const isFormData = typeof FormData !== "undefined" && body instanceof FormData;
    const requestHeaders = {
        Accept: "application/json",
        ...(body !== undefined && !isFormData ? { "Content-Type": "application/json" } : {}),
        ...(token ? { "X-XSRF-TOKEN": token } : {}),
        ...headers,
    };
    if (isFormData && "Content-Type" in requestHeaders) {
        delete requestHeaders["Content-Type"];
    }
    const requestInit: RequestInit = {
        ...init,
        credentials: "include",
        headers: requestHeaders,
    };
    if (body !== undefined) requestInit.body = isFormData ? body : JSON.stringify(body);
    let response = await fetch(buildUrl(path, query), requestInit);

    if (response.status === 419 && usesCsrf && path !== "/sanctum/csrf-cookie") {
        await ensureCsrfCookie(true);
        const refreshedToken = xsrfToken();
        const retryHeaders = new Headers(requestHeaders);

        if (refreshedToken) {
            retryHeaders.set("X-XSRF-TOKEN", refreshedToken);
        }

        response = await fetch(buildUrl(path, query), {
            ...requestInit,
            headers: retryHeaders,
        });
    }

    if (!response.ok) throw await parseError(response);
    if (response.status === 204) return undefined as T;
    return (await response.json()) as T;
}

export async function csrfCookie(): Promise<void> {
    await apiRequest<void>("/sanctum/csrf-cookie");
}

export function errorMessage(error: unknown): string {
    return error instanceof ApiError
        ? error.message
        : "Không thể kết nối máy chủ. Vui lòng thử lại.";
}

export function firstFieldErrors(error: unknown): Record<string, string> {
    if (!(error instanceof ApiError)) return {};
    return Object.fromEntries(
        Object.entries(error.errors).map(([key, messages]) => [key, messages[0] ?? error.message]),
    );
}
