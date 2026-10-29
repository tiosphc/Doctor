import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { Container } from "@/components/common/Container";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import {
    BookingCTA,
    CategoryServicePresentation,
    ServiceExplorer,
} from "@/components/services/ServiceExplorer";
import { useServiceCategories } from "@/components/services/serviceQueries";
import { errorMessage } from "@/services/api";
import { serviceApi } from "@/services/serviceApi";

export function ServicesPage() {
    const query = useServiceCategories();

    if (query.isPending) {
        return (
            <Container className="section-space">
                <LoadingState label="Đang khám phá danh mục dịch vụ..." />
            </Container>
        );
    }
    if (query.isError) {
        return (
            <Container className="section-space">
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            </Container>
        );
    }
    if (query.data.data.length === 0) {
        return (
            <Container className="section-space">
                <EmptyState message="Hiện chưa có dịch vụ đang hoạt động." />
            </Container>
        );
    }

    return <ServiceExplorer categories={query.data.data} />;
}

export function ServiceCategoryPage({ slug }: { slug: string }) {
    const query = useQuery({
        queryKey: ["service-category", slug],
        queryFn: () => serviceApi.category(slug),
        retry: false,
    });

    if (query.isPending) {
        return (
            <Container className="section-space">
                <LoadingState label="Đang tải danh mục dịch vụ..." />
            </Container>
        );
    }
    if (query.isError) {
        return (
            <Container className="section-space">
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            </Container>
        );
    }

    const category = query.data.data;
    return (
        <>
            <CategoryServicePresentation category={category} />
            <Container className="section-space pt-0">
                <div className="mb-8 flex items-center justify-between gap-4 border-b pb-5">
                    <div>
                        <p className="label-luxury">Bạn đang xem</p>
                        <p className="mt-2 text-sm text-muted-foreground">{category.name}</p>
                    </div>
                    <Link
                        to="/services"
                        className="focus-premium text-sm font-semibold text-primary transition hover:text-secondary"
                    >
                        Đổi danh mục
                    </Link>
                </div>
                <BookingCTA title="Cần một lựa chọn phù hợp hơn?" />
            </Container>
        </>
    );
}
