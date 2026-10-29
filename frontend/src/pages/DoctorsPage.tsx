import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { Container, SectionHeading } from "@/components/common/Container";
import { Input } from "@/components/common/Fields";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { DoctorCard } from "@/components/cards/Cards";
import { doctorApi } from "@/services/doctorApi";
import { errorMessage } from "@/services/api";

export function DoctorsPage() {
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const query = useQuery({
        queryKey: ["doctors", { search, page }],
        queryFn: () => doctorApi.list({ search: search || undefined, page }),
    });
    return (
        <Container className="section-space">
            <SectionHeading
                eyebrow="Chuyên gia đồng hành"
                title="Đội ngũ bác sĩ"
                description="Tìm hiểu hồ sơ và chọn bác sĩ phù hợp với dịch vụ bạn quan tâm."
            />
            <Input
                className="mt-8 max-w-md"
                value={search}
                onChange={(event) => {
                    setSearch(event.target.value);
                    setPage(1);
                }}
                placeholder="Tìm theo tên hoặc chuyên môn..."
            />
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <div className="mt-8">
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                </div>
            ) : query.data.data.length === 0 ? (
                <div className="mt-8">
                    <EmptyState message="Không tìm thấy bác sĩ phù hợp." />
                </div>
            ) : (
                <>
                    <div className="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                        {query.data.data.map((doctor) => (
                            <DoctorCard key={doctor.id} doctor={doctor} />
                        ))}
                    </div>
                    <Pagination
                        current={query.data.meta.current_page}
                        last={query.data.meta.last_page}
                        onPage={setPage}
                    />
                </>
            )}
        </Container>
    );
}
