import { createFileRoute } from "@tanstack/react-router";
import { SetupDoctorPasswordPage } from "@/pages/AuthPages";

type SetupPasswordSearch = {
    email: string;
    token: string;
};

export const Route = createFileRoute("/setup-password")({
    validateSearch: (search: Record<string, unknown>): SetupPasswordSearch => ({
        email: typeof search["email"] === "string" ? search["email"] : "",
        token: typeof search["token"] === "string" ? search["token"] : "",
    }),
    head: () => ({
        meta: [
            { title: "Thiết lập tài khoản bác sĩ | Junie" },
            {
                name: "description",
                content: "Thiết lập mật khẩu an toàn cho tài khoản bác sĩ JUNIE.",
            },
            { name: "robots", content: "noindex,nofollow" },
        ],
    }),
    component: SetupPasswordRoute,
});

function SetupPasswordRoute() {
    const { email, token } = Route.useSearch();

    return <SetupDoctorPasswordPage email={email} token={token} />;
}
