import { createFileRoute } from "@tanstack/react-router";
import { ProductsPage } from "@/pages/ProductPages";
export const Route = createFileRoute("/products/")({
    head: () => ({ meta: [{ title: "Sản phẩm | Junie" }] }),
    component: ProductsPage,
});
