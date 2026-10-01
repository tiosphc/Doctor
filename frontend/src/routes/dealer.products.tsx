import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/dealer/products")({ component: () => <Outlet /> });
