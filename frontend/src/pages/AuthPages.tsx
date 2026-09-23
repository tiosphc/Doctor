import { useState, type FormEvent } from "react";
import { Link, useNavigate } from "@tanstack/react-router";
import { CheckCircle2, Eye, EyeOff } from "lucide-react";
import { Container } from "@/components/common/Container";
import { Button, ButtonLink } from "@/components/common/Button";
import { Field, Input } from "@/components/common/Fields";
import { useAuth } from "@/contexts/AuthContext";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { authApi } from "@/services/authApi";

export function LoginPage() {
    const { login, isLoading } = useAuth();
    const navigate = useNavigate();
    const [error, setError] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError("");
        setErrors({});
        const form = new FormData(event.currentTarget);
        try {
            const user = await login({
                email: String(form.get("email")),
                password: String(form.get("password")),
            });
            const destination =
                user.role === "admin"
                    ? "/admin"
                    : user.role === "receptionist"
                      ? "/receptionist"
                      : user.role === "doctor"
                        ? "/doctor"
                        : "/account";
            await navigate({ to: destination });
        } catch (reason) {
            setError(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }

    return (
        <AuthFrame
            eyebrow="Chào mừng trở lại"
            title="Đăng nhập tài khoản"
            footer={
                <>
                    Chưa có tài khoản?{" "}
                    <Link to="/register" className="font-semibold text-primary">
                        Tạo tài khoản
                    </Link>
                </>
            }
        >
            <form onSubmit={submit} className="grid gap-5">
                <Field label="Email" error={errors["email"]}>
                    <Input name="email" type="email" autoComplete="email" required />
                </Field>
                <Field label="Mật khẩu" error={errors["password"]}>
                    <Input
                        name="password"
                        type="password"
                        autoComplete="current-password"
                        required
                    />
                </Field>
                {error && (
                    <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700">
                        {error}
                    </p>
                )}
                <Button disabled={isLoading} type="submit">
                    {isLoading ? "Đang đăng nhập..." : "Đăng nhập"}
                </Button>
                <Link to="/forgot-password" className="text-center text-sm text-muted-foreground">
                    Quên mật khẩu?
                </Link>
            </form>
        </AuthFrame>
    );
}

export function RegisterPage() {
    const { register, isLoading } = useAuth();
    const navigate = useNavigate();
    const [error, setError] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setError("");
        setErrors({});
        try {
            await register({
                name: String(form.get("name")),
                email: String(form.get("email")),
                phone: String(form.get("phone")),
                password: String(form.get("password")),
                password_confirmation: String(form.get("password_confirmation")),
            });
            await navigate({ to: "/account" });
        } catch (reason) {
            setError(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }

    return (
        <AuthFrame
            eyebrow="Hành trình của bạn"
            title="Tạo tài khoản"
            footer={
                <>
                    Đã có tài khoản?{" "}
                    <Link to="/login" className="font-semibold text-primary">
                        Đăng nhập
                    </Link>
                </>
            }
        >
            <form onSubmit={submit} className="grid gap-5">
                <Field label="Họ và tên" error={errors["name"]}>
                    <Input name="name" autoComplete="name" required />
                </Field>
                <Field label="Email" error={errors["email"]}>
                    <Input name="email" type="email" autoComplete="email" required />
                </Field>
                <Field label="Số điện thoại" error={errors["phone"]}>
                    <Input name="phone" inputMode="tel" autoComplete="tel" />
                </Field>
                <Field label="Mật khẩu" error={errors["password"]}>
                    <Input
                        name="password"
                        type="password"
                        autoComplete="new-password"
                        minLength={8}
                        required
                    />
                </Field>
                <Field label="Xác nhận mật khẩu">
                    <Input
                        name="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        minLength={8}
                        required
                    />
                </Field>
                {error && (
                    <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700">
                        {error}
                    </p>
                )}
                <Button disabled={isLoading} type="submit">
                    {isLoading ? "Đang tạo tài khoản..." : "Tạo tài khoản"}
                </Button>
            </form>
        </AuthFrame>
    );
}

export function ForgotPasswordPage() {
    return (
        <AuthFrame
            eyebrow="Sắp ra mắt"
            title="Khôi phục mật khẩu"
            footer={
                <Link to="/login" className="font-semibold text-primary">
                    Quay lại đăng nhập
                </Link>
            }
        >
            <div className="rounded-md bg-muted p-5 text-sm leading-6 text-muted-foreground">
                Backend hiện chưa có API khôi phục mật khẩu. Tính năng này sẽ được mở khi quy trình
                email bảo mật được bổ sung.
            </div>
        </AuthFrame>
    );
}

export function SetupDoctorPasswordPage({ email, token }: { email: string; token: string }) {
    const [showPassword, setShowPassword] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [success, setSuccess] = useState("");
    const [error, setError] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const hasInvitation = email.length > 0 && token.length > 0;

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError("");
        setErrors({});
        setIsSubmitting(true);
        const form = new FormData(event.currentTarget);

        try {
            const message = await authApi.setupDoctorPassword({
                email,
                token,
                password: String(form.get("password")),
                password_confirmation: String(form.get("password_confirmation")),
            });
            setSuccess(message);
        } catch (reason) {
            setError(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        } finally {
            setIsSubmitting(false);
        }
    }

    return (
        <AuthFrame
            eyebrow="Tài khoản bác sĩ"
            title="Thiết lập mật khẩu"
            footer={
                <Link to="/login" className="font-semibold text-primary">
                    Quay lại đăng nhập
                </Link>
            }
        >
            {success ? (
                <div className="grid gap-5 text-center">
                    <CheckCircle2 className="mx-auto size-12 text-emerald-600" aria-hidden="true" />
                    <p className="text-sm leading-6 text-muted-foreground">{success}</p>
                    <ButtonLink to="/login">Đăng nhập ngay</ButtonLink>
                </div>
            ) : !hasInvitation ? (
                <p role="alert" className="rounded-md bg-amber-50 p-4 text-sm text-amber-800">
                    Liên kết thiết lập tài khoản không đầy đủ. Vui lòng mở lại liên kết trong email
                    mời hoặc liên hệ quản trị viên.
                </p>
            ) : (
                <form onSubmit={submit} className="grid gap-5">
                    <Field label="Email bác sĩ">
                        <Input value={email} type="email" readOnly aria-readonly="true" />
                    </Field>
                    <Field label="Mật khẩu mới" error={errors["password"]}>
                        <div className="relative">
                            <Input
                                name="password"
                                type={showPassword ? "text" : "password"}
                                autoComplete="new-password"
                                minLength={8}
                                className="pr-12"
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword((visible) => !visible)}
                                className="absolute inset-y-0 right-0 grid w-11 place-items-center text-muted-foreground"
                                aria-label={showPassword ? "Ẩn mật khẩu" : "Hiện mật khẩu"}
                            >
                                {showPassword ? (
                                    <EyeOff className="size-4" />
                                ) : (
                                    <Eye className="size-4" />
                                )}
                            </button>
                        </div>
                    </Field>
                    <Field label="Xác nhận mật khẩu" error={errors["password_confirmation"]}>
                        <Input
                            name="password_confirmation"
                            type={showPassword ? "text" : "password"}
                            autoComplete="new-password"
                            minLength={8}
                            required
                        />
                    </Field>
                    {error && (
                        <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <Button disabled={isSubmitting} type="submit">
                        {isSubmitting ? "Đang thiết lập..." : "Thiết lập mật khẩu"}
                    </Button>
                </form>
            )}
        </AuthFrame>
    );
}

function AuthFrame({
    eyebrow,
    title,
    children,
    footer,
}: {
    eyebrow: string;
    title: string;
    children: React.ReactNode;
    footer: React.ReactNode;
}) {
    return (
        <Container className="py-14 md:py-20">
            <div className="card-surface mx-auto max-w-lg p-6 md:p-9">
                <p className="label-luxury text-center">{eyebrow}</p>
                <h1 className="mt-3 text-center text-4xl text-primary">{title}</h1>
                <div className="mt-8">{children}</div>
                <p className="mt-7 border-t pt-6 text-center text-sm text-muted-foreground">
                    {footer}
                </p>
            </div>
        </Container>
    );
}
