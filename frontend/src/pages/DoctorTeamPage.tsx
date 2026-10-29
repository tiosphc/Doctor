import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { ArrowUpRight } from "lucide-react";
import { DoctorPortrait } from "@/components/landing/DoctorPortrait";
import { Footer } from "@/components/landing/Sections";
import { LuxButton } from "@/components/landing/LuxButton";
import { Nav } from "@/components/landing/Nav";
import { SectionLabel } from "@/components/landing/SectionLabel";
import { servicePath } from "@/components/services/ServiceExplorer";
import { useFeaturedDoctor } from "@/hooks/useFeaturedDoctor";
import { useScrollReveal } from "@/hooks/use-lux-motion";
import { doctorApi } from "@/services/doctorApi";
import "@/components/landing/LandingTheme.css";

export function DoctorTeamPage() {
    useScrollReveal();
    const { doctor, query } = useFeaturedDoctor();
    const detailQuery = useQuery({
        queryKey: ["doctor", doctor?.id],
        queryFn: () => doctorApi.find(doctor!.id),
        enabled: Boolean(doctor),
        retry: false,
    });
    const profile = detailQuery.data?.data ?? doctor;

    return (
        <div className="lovable-home min-h-screen bg-ivory">
            <Nav />
            <main id="trang-chu" className="min-h-[70svh]">
                <section className="border-b border-border bg-cream px-6 pb-12 pt-36 md:px-10 md:pb-16 md:pt-40">
                    <div className="reveal mx-auto max-w-[1120px]">
                        <SectionLabel label="Đội ngũ Junie" />
                        <h1 className="mt-5 max-w-[900px] text-[clamp(3rem,6.5vw,6rem)] leading-[0.98] text-espresso">
                            Chuyên môn là nền tảng của{" "}
                            <em className="text-mocha">vẻ đẹp bền vững.</em>
                        </h1>
                        <p className="mt-6 max-w-[660px] text-sm leading-7 text-muted-foreground md:text-base">
                            Junie bắt đầu mỗi buổi tư vấn bằng việc lắng nghe, tìm hiểu làn da và
                            cùng khách hàng lựa chọn hướng chăm sóc phù hợp.
                        </p>
                    </div>
                </section>

                <section
                    className="px-6 py-14 md:px-10 md:py-20"
                    aria-labelledby="doctor-profile-heading"
                >
                    <div className="reveal mx-auto max-w-[1120px]">
                        {query.isPending ? (
                            <p role="status" className="text-sm text-muted-foreground">
                                Đang tải hồ sơ bác sĩ...
                            </p>
                        ) : query.isError ? (
                            <div
                                role="alert"
                                className="border-y border-border py-8 text-sm text-muted-foreground"
                            >
                                Chưa thể tải hồ sơ bác sĩ.{" "}
                                <button
                                    type="button"
                                    onClick={() => void query.refetch()}
                                    className="text-mocha underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha"
                                >
                                    Thử lại
                                </button>
                            </div>
                        ) : !profile ? (
                            <p className="border-y border-border py-8 text-sm text-muted-foreground">
                                Hồ sơ bác sĩ đang được cập nhật.
                            </p>
                        ) : (
                            <div className="grid items-center gap-8 md:grid-cols-[minmax(0,0.75fr)_minmax(0,1.25fr)] md:gap-14">
                                <DoctorPortrait
                                    key={profile.avatar ?? profile.id}
                                    doctor={profile}
                                    className="aspect-[4/3] w-full max-w-[440px] md:aspect-[4/5] md:max-h-[520px]"
                                />
                                <div className="min-w-0">
                                    <SectionLabel label="Hồ sơ bác sĩ" />
                                    <h2
                                        id="doctor-profile-heading"
                                        className="mt-5 break-words text-[clamp(2.6rem,5vw,4.5rem)] leading-[1.02] text-espresso"
                                    >
                                        {profile.name}
                                    </h2>
                                    <p className="mt-2 text-sm uppercase tracking-[0.16em] text-mocha">
                                        Bác sĩ · {profile.specialty}
                                    </p>
                                    <div className="mt-7 border-t border-border pt-5">
                                        <h3 className="text-xl text-espresso">Giới thiệu</h3>
                                        <p className="mt-2 max-w-[590px] whitespace-pre-line text-sm leading-7 text-muted-foreground">
                                            {profile.bio ||
                                                "Thông tin giới thiệu về bác sĩ sẽ được cập nhật sau khi xác minh."}
                                        </p>
                                    </div>
                                    <div className="mt-5 border-t border-border pt-5">
                                        <h3 className="text-xl text-espresso">Định hướng tư vấn</h3>
                                        <p className="mt-2 max-w-[590px] text-sm leading-7 text-muted-foreground">
                                            Lắng nghe nhu cầu, đánh giá tình trạng da và trao đổi rõ
                                            ràng về hướng chăm sóc phù hợp với từng khách hàng.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                </section>

                {profile && (
                    <>
                        <section
                            className="bg-cream px-6 py-14 md:px-10 md:py-16"
                            aria-labelledby="doctor-credentials-heading"
                        >
                            <div className="mx-auto grid max-w-[1120px] gap-8 md:grid-cols-[minmax(0,0.7fr)_minmax(0,1.3fr)] md:gap-14">
                                <div>
                                    <SectionLabel label="Thông tin chuyên môn" />
                                    <h2
                                        id="doctor-credentials-heading"
                                        className="mt-5 text-[clamp(2.5rem,4vw,4rem)] leading-[1.02] text-espresso"
                                    >
                                        Đào tạo &amp; kinh nghiệm
                                    </h2>
                                    <p className="mt-4 max-w-sm text-sm leading-7 text-muted-foreground">
                                        Chỉ những thông tin đã được xác minh mới được công bố trong
                                        hồ sơ.
                                    </p>
                                </div>
                                <dl className="border-t border-border">
                                    {[
                                        ["Đào tạo", "Chưa có thông tin xác minh để công bố."],
                                        [
                                            "Bằng cấp và chứng chỉ",
                                            "Chưa có thông tin xác minh để công bố.",
                                        ],
                                        [
                                            "Kinh nghiệm công tác",
                                            "Chưa có thông tin xác minh để công bố.",
                                        ],
                                        ["Lĩnh vực chuyên môn", profile.specialty],
                                    ].map(([label, value]) => (
                                        <div
                                            key={label}
                                            className="grid gap-1 border-b border-border py-4 sm:grid-cols-[170px_minmax(0,1fr)] sm:gap-6"
                                        >
                                            <dt className="text-xs uppercase tracking-[0.12em] text-mocha">
                                                {label}
                                            </dt>
                                            <dd className="break-words text-sm leading-6 text-espresso">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        </section>

                        <section
                            className="px-6 py-14 md:px-10 md:py-16"
                            aria-labelledby="doctor-services-heading"
                        >
                            <div className="mx-auto max-w-[1120px]">
                                <SectionLabel label="Chăm sóc theo nhu cầu" />
                                <h2
                                    id="doctor-services-heading"
                                    className="mt-5 text-[clamp(2.5rem,4vw,4rem)] leading-[1.02] text-espresso"
                                >
                                    Dịch vụ phụ trách
                                </h2>
                                {detailQuery.isPending ? (
                                    <p role="status" className="mt-6 text-sm text-muted-foreground">
                                        Đang tải danh sách dịch vụ...
                                    </p>
                                ) : detailQuery.isError ? (
                                    <p role="alert" className="mt-6 text-sm text-muted-foreground">
                                        Chưa thể tải danh sách dịch vụ.
                                    </p>
                                ) : profile.services?.length ? (
                                    <ul className="mt-7 grid border-t border-border sm:grid-cols-2 sm:gap-x-8">
                                        {profile.services.map((service) => (
                                            <li key={service.id} className="border-b border-border">
                                                <Link
                                                    to={servicePath(service)}
                                                    className="group flex min-h-16 items-center justify-between gap-4 py-3 text-sm text-espresso transition-colors hover:text-mocha focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha"
                                                >
                                                    <span className="break-words">
                                                        {service.name}
                                                    </span>
                                                    <ArrowUpRight
                                                        size={17}
                                                        strokeWidth={1.3}
                                                        className="shrink-0 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5"
                                                        aria-hidden="true"
                                                    />
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="mt-6 text-sm text-muted-foreground">
                                        Danh sách dịch vụ đang được cập nhật.
                                    </p>
                                )}
                            </div>
                        </section>
                    </>
                )}

                <section
                    className="border-t border-border bg-sand px-6 py-14 text-center md:px-10 md:py-20"
                    aria-labelledby="doctor-booking-heading"
                >
                    <div className="reveal mx-auto max-w-[720px]">
                        <SectionLabel label="Lịch tư vấn" />
                        <h2
                            id="doctor-booking-heading"
                            className="mt-5 text-[clamp(2.7rem,5vw,4.5rem)] leading-[1.02] text-espresso"
                        >
                            Khởi đầu hành trình chăm sóc cùng Junie
                        </h2>
                        <p className="mx-auto mt-5 max-w-xl text-sm leading-7 text-muted-foreground">
                            Chọn dịch vụ và thời gian phù hợp để Junie tiếp nhận yêu cầu đặt lịch
                            của bạn.
                        </p>
                        <div className="mt-7 flex justify-center">
                            <LuxButton href="/booking" variant="solid">
                                Đặt lịch tư vấn
                            </LuxButton>
                        </div>
                        <Link
                            to="/"
                            className="mt-5 inline-block text-xs text-mocha underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha"
                        >
                            Về trang chủ
                        </Link>
                    </div>
                </section>
            </main>
            <Footer />
        </div>
    );
}
