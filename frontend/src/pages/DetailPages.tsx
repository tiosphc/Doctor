import { useQuery } from "@tanstack/react-query";
import { Container } from "@/components/common/Container";
import { ButtonLink } from "@/components/common/Button";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { doctorImage } from "@/components/cards/Cards";
import { doctorApi } from "@/services/doctorApi";
import { errorMessage } from "@/services/api";
import { DoctorReviewsSection } from "@/components/reviews/ReviewExperience";

export function DoctorDetailPage({ slug }: { slug: string }) {
    const query = useQuery({
        queryKey: ["doctor", slug],
        queryFn: () => doctorApi.find(slug),
        retry: false,
    });
    if (query.isPending)
        return (
            <Container className="section-space">
                <LoadingState />
            </Container>
        );
    if (query.isError)
        return (
            <Container className="section-space">
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            </Container>
        );
    const doctor = query.data.data;
    return (
        <Container className="section-space">
            <div className="grid items-center gap-10 lg:grid-cols-[.75fr_1.25fr]">
                <img
                    src={doctorImage(doctor)}
                    alt={doctor.name}
                    className="mx-auto aspect-[4/5] max-w-md rounded-t-[9rem] rounded-b-lg object-cover shadow-card"
                />
                <div>
                    <p className="label-luxury">{doctor.specialty}</p>
                    <h1 className="mt-4 text-4xl text-primary md:text-5xl">{doctor.name}</h1>
                    <p className="mt-5 leading-7 text-muted-foreground">
                        {doctor.bio ||
                            "Bác sĩ đồng hành cùng khách hàng bằng phác đồ an toàn và cá nhân hóa."}
                    </p>
                    {doctor.services && doctor.services.length > 0 && (
                        <div className="mt-7">
                            <h2 className="text-xl text-primary">Dịch vụ phụ trách</h2>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {doctor.services.map((service) => (
                                    <span
                                        key={service.id}
                                        className="rounded-full bg-muted px-4 py-2 text-sm"
                                    >
                                        {service.name}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
                    <ButtonLink to="/booking" className="mt-8">
                        Đặt lịch với bác sĩ
                    </ButtonLink>
                </div>
            </div>
            <DoctorReviewsSection doctor={doctor} />
        </Container>
    );
}
