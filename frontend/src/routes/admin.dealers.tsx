import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/admin/dealers")({ component: () => <Outlet /> });
