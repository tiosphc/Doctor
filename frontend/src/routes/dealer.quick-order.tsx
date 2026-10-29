import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/dealer/quick-order")({ component: () => <Outlet /> });
