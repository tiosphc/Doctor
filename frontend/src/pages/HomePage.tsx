import { useQuery } from "@tanstack/react-query";
import { ArrowRight, CalendarDays, Clock3, Sparkles } from "lucide-react";
import hero from "@/assets/clinic-hero.jpg";
import treatment from "@/assets/treatment.jpg";
import { Container, SectionHeading } from "@/components/common/Container";
import { ButtonLink } from "@/components/common/Button";
import { money } from "@/components/cards/Cards";
import { servicePath } from "@/components/services/ServiceExplorer";
import { RetailProductCard } from "@/components/retail/RetailProductCard";
import { serviceApi } from "@/services/serviceApi";
import { doctorApi } from "@/services/doctorApi";
import { productApi, productKeys } from "@/services/productApi";
import type { Doctor, Service } from "@/types";

const productFilters = { page: 1, sort: "newest" as const };

export function HomePage() {
    const servicesQuery = useQuery({
        queryKey: ["services", { home: true }],
        queryFn: () => serviceApi.list(),
    });
    const doctorsQuery = useQuery({
        queryKey: ["doctors", { featured: true }],
        queryFn: () => doctorApi.list(),
    });
    const productsQuery = useQuery({
        queryKey: productKeys.catalog(productFilters),
        queryFn: () => productApi.catalog(productFilters),
    });
    const services = servicesQuery.data?.data ?? [];
    const doctors = doctorsQuery.data?.data ?? [];
    const products = productsQuery.data?.data ?? [];

    return (
        <>
            <section className="relative isolate flex min-h-[700px] items-center overflow-hidden bg-[#302d29] text-white lg:min-h-[760px]">
                <img
                    src={hero}
                    alt="Hình minh họa không gian phòng khám"
                    fetchPriority="high"
                    className="absolute inset-0 -z-30 h-full w-full object-cover"
                />
                <div className="absolute inset-0 -z-20 bg-[linear-gradient(90deg,rgba(28,27,25,.9),rgba(37,35,32,.66)_55%,rgba(31,30,28,.34))]" />
                <Container className="grid gap-12 pb-28 pt-40 lg:grid-cols-[minmax(0,1.2fr)_minmax(310px,.7fr)] lg:items-center lg:gap-16 lg:pb-32">
                    <div className="max-w-3xl">
                        <p className="inline-flex items-center gap-2 rounded-full border border-[#dbc49e]/60 bg-black/15 px-4 py-2 text-xs font-semibold uppercase tracking-[.16em] text-[#f3dfbc] backdrop-blur-sm">
                            <Sparkles size={14} /> Da liễu · Thẩm mỹ · Chăm sóc da
                        </p>
                        <h1 className="mt-7 font-display text-[clamp(2.75rem,5.5vw,5.5rem)] font-medium leading-[1.08] tracking-[-.035em]">
                            Nâng niu làn da,
                            <br />
                            <span className="italic text-[#e9cfaa]">tôn vinh vẻ đẹp tự nhiên</span>
                        </h1>
                        <p className="mt-7 max-w-2xl text-base leading-8 text-white/85 md:text-lg">
                            JUNIE đồng hành cùng bạn từ buổi tư vấn đầu tiên đến hành trình chăm sóc
                            da phù hợp với nhu cầu riêng.
                        </p>
                        <div className="mt-9 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                            <ButtonLink
                                to="/booking"
                                className="border-[#cba56c] bg-[#b99159] px-7 text-[#201d18] hover:bg-[#d2ad75]"
                            >
                                <CalendarDays size={18} /> Đặt lịch ngay
                            </ButtonLink>
                            <ButtonLink
                                to="/services"
                                variant="outline"
                                className="border-white/70 px-7 text-white hover:bg-white/10 hover:text-white"
                            >
                                Khám phá dịch vụ <ArrowRight size={17} />
                            </ButtonLink>
                        </div>
                    </div>
                    <div className="hidden rounded-2xl border border-white/25 bg-[#302d29]/60 p-7 shadow-2xl backdrop-blur-lg lg:block">
                        <p className="text-xs font-semibold uppercase tracking-[.18em] text-[#ead0a5]">
                            Bắt đầu tại JUNIE
                        </p>
                        <h2 className="mt-4 text-3xl leading-tight">
                            Một buổi hẹn dành riêng cho làn da của bạn
                        </h2>
                        <p className="mt-4 text-sm leading-7 text-white/75">
                            Chọn dịch vụ, bác sĩ và thời gian thuận tiện trên trang đặt lịch.
                        </p>
                        <ButtonLink
                            to="/booking"
                            className="mt-7 w-full border-[#e6d1ac] bg-[#f2e8d8] text-[#2b2925] hover:bg-white"
                        >
                            Chọn lịch hẹn <ArrowRight size={17} />
                        </ButtonLink>
                    </div>
                </Container>
                <div className="absolute inset-x-0 bottom-0 border-t border-white/25 bg-[#242422]/45 py-4 backdrop-blur-sm">
                    <Container className="flex flex-wrap gap-x-8 gap-y-2 text-xs font-medium text-white/85 md:justify-between md:text-sm">
                        <span>Tư vấn theo nhu cầu làn da</span>
                        <span>Dịch vụ da liễu và thẩm mỹ</span>
                        <span>Đặt lịch trực tuyến thuận tiện</span>
                    </Container>
                </div>
            </section>

            <section id="pricing" className="section-space scroll-mt-20 bg-[#fbf8f3]">
                <Container>
                    <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
                        <SectionHeading
                            eyebrow="Dịch vụ tại JUNIE"
                            title="Chăm sóc làn da theo cách của bạn"
                            description="Khám phá dịch vụ hiện có và xem giá tham khảo khi được công bố. Thông tin chi tiết được xác nhận trong quá trình đặt lịch."
                        />
                        <ButtonLink
                            to="/services"
                            variant="ghost"
                            className="self-start px-0 text-[#705637] hover:bg-transparent"
                        >
                            Tất cả dịch vụ <ArrowRight size={17} />
                        </ButtonLink>
                    </div>
                    {servicesQuery.isPending ? (
                        <p className="mt-10 text-sm text-muted-foreground">Đang tải dịch vụ...</p>
                    ) : servicesQuery.isError ? (
                        <p className="mt-10 text-sm text-red-700">Chưa thể tải dịch vụ lúc này.</p>
                    ) : services.length === 0 ? (
                        <p className="mt-10 text-sm text-muted-foreground">
                            Danh sách dịch vụ đang được cập nhật.
                        </p>
                    ) : (
                        <div className="mt-10 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                            {services.slice(0, 6).map((service) => (
                                <HomeServiceCard key={service.id} service={service} />
                            ))}
                        </div>
                    )}
                </Container>
            </section>

            <section id="about" className="section-space scroll-mt-20 bg-white">
                <Container className="grid items-center gap-10 lg:grid-cols-2 lg:gap-20">
                    <div className="overflow-hidden rounded-t-[9rem] rounded-b-2xl bg-[#e8dfd1]">
                        <img
                            src={hero}
                            alt="Hình minh họa không gian phòng khám"
                            loading="lazy"
                            width={1200}
                            height={900}
                            className="aspect-[5/4] w-full object-cover"
                        />
                    </div>
                    <div>
                        <SectionHeading
                            eyebrow="Về JUNIE"
                            title="Một không gian dành cho sự an tâm và vẻ đẹp tự nhiên"
                        />
                        <p className="mt-6 text-base leading-8 text-muted-foreground">
                            JUNIE giới thiệu các dịch vụ da liễu và thẩm mỹ trong một hành trình rõ
                            ràng: tìm hiểu dịch vụ, chọn lịch hẹn và trao đổi trực tiếp với đội ngũ
                            chuyên môn.
                        </p>
                        <div className="mt-8 grid gap-4 border-t border-[#e5daca] pt-7 text-sm leading-6 sm:grid-cols-2">
                            <p>✦ Tìm hiểu dịch vụ và chi phí trước khi đặt lịch</p>
                            <p>✦ Chọn khung giờ phù hợp với bạn</p>
                        </div>
                        <ButtonLink
                            to="/booking"
                            variant="outline"
                            className="mt-8 border-[#bd9d74]"
                        >
                            Đặt lịch tư vấn <ArrowRight size={17} />
                        </ButtonLink>
                    </div>
                </Container>
            </section>

            <section className="section-space bg-[#f4eee5]">
                <Container>
                    <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
                        <SectionHeading
                            eyebrow="Đội ngũ JUNIE"
                            title="Gặp gỡ bác sĩ"
                            description="Thông tin đội ngũ từ hồ sơ bác sĩ đang hiển thị trên hệ thống JUNIE."
                        />
                        <ButtonLink
                            to="/doctors"
                            variant="ghost"
                            className="self-start px-0 text-[#705637] hover:bg-transparent"
                        >
                            Xem đội ngũ <ArrowRight size={17} />
                        </ButtonLink>
                    </div>
                    {doctorsQuery.isPending ? (
                        <p className="mt-10 text-sm text-muted-foreground">Đang tải đội ngũ...</p>
                    ) : doctorsQuery.isError ? (
                        <p className="mt-10 text-sm text-red-700">
                            Chưa thể tải thông tin bác sĩ lúc này.
                        </p>
                    ) : doctors.length === 0 ? (
                        <p className="mt-10 text-sm text-muted-foreground">
                            Thông tin đội ngũ đang được cập nhật.
                        </p>
                    ) : (
                        <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {doctors.slice(0, 3).map((doctor) => (
                                <HomeDoctorCard key={doctor.id} doctor={doctor} />
                            ))}
                        </div>
                    )}
                </Container>
            </section>

            <section className="section-space bg-white">
                <Container>
                    <SectionHeading
                        center
                        eyebrow="Quy trình đặt lịch"
                        title="Chỉ vài bước để bắt đầu"
                        description="Đặt lịch qua luồng hiện có của JUNIE và theo dõi thông tin hẹn của bạn."
                    />
                    <div className="mt-12 grid gap-5 md:grid-cols-3">
                        {[
                            [
                                "01",
                                "Chọn dịch vụ",
                                "Xem thông tin dịch vụ và chọn điều bạn muốn tìm hiểu.",
                            ],
                            [
                                "02",
                                "Chọn bác sĩ & thời gian",
                                "Chọn bác sĩ và khung giờ còn trống trên trang đặt lịch.",
                            ],
                            [
                                "03",
                                "Xác nhận lịch hẹn",
                                "Điền thông tin liên hệ và hoàn tất các bước xác nhận.",
                            ],
                        ].map(([number, title, description]) => (
                            <div
                                key={number}
                                className="rounded-xl border border-[#e8ddcb] bg-[#fbf8f3] p-7"
                            >
                                <span className="font-display text-4xl italic text-[#bb9a6a]">
                                    {number}
                                </span>
                                <h3 className="mt-5 text-2xl text-[#2c2a27]">{title}</h3>
                                <p className="mt-3 text-sm leading-7 text-muted-foreground">
                                    {description}
                                </p>
                            </div>
                        ))}
                    </div>
                </Container>
            </section>

            <section className="bg-[#373630] py-16 text-white md:py-24">
                <Container className="grid items-center gap-8 lg:grid-cols-[1.3fr_.7fr]">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[.2em] text-[#e2c79d]">
                            Lịch hẹn tại JUNIE
                        </p>
                        <h2 className="mt-4 max-w-2xl text-4xl leading-tight md:text-5xl">
                            Dành thời gian cho làn da của bạn
                        </h2>
                        <p className="mt-5 max-w-xl text-sm leading-7 text-white/75">
                            Chọn dịch vụ và thời gian thuận tiện trên trang đặt lịch JUNIE.
                        </p>
                    </div>
                    <ButtonLink
                        to="/booking"
                        className="w-full border-[#d7bb91] bg-[#d7bb91] px-8 py-4 text-[#292621] hover:bg-[#ead4b2] lg:justify-self-end"
                    >
                        Đặt lịch hẹn <ArrowRight size={18} />
                    </ButtonLink>
                </Container>
            </section>

            <section className="section-space bg-[#fbf8f3]">
                <Container>
                    <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
                        <SectionHeading
                            eyebrow="Chăm sóc tại nhà"
                            title="Sản phẩm tiêu biểu"
                            description="Một số sản phẩm chăm sóc da từ danh mục bán lẻ của JUNIE."
                        />
                        <ButtonLink
                            to="/products"
                            variant="ghost"
                            className="self-start px-0 text-[#705637] hover:bg-transparent"
                        >
                            Xem sản phẩm <ArrowRight size={17} />
                        </ButtonLink>
                    </div>
                    {productsQuery.isPending ? (
                        <p className="mt-10 text-sm text-muted-foreground">Đang tải sản phẩm...</p>
                    ) : productsQuery.isError ? (
                        <p className="mt-10 text-sm text-red-700">Chưa thể tải sản phẩm lúc này.</p>
                    ) : products.length === 0 ? (
                        <p className="mt-10 text-sm text-muted-foreground">
                            Sản phẩm đang được cập nhật.
                        </p>
                    ) : (
                        <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {products.slice(0, 3).map((product) => (
                                <RetailProductCard key={product.id} product={product} />
                            ))}
                        </div>
                    )}
                </Container>
            </section>

            <section className="section-space bg-white">
                <Container className="grid gap-10 lg:grid-cols-[.75fr_1.25fr] lg:gap-16">
                    <SectionHeading eyebrow="Câu hỏi thường gặp" title="Trước khi đến JUNIE" />
                    <div className="divide-y divide-[#e9dfd1] border-y border-[#e9dfd1]">
                        <Faq
                            question="Tôi có thể đặt lịch trực tuyến không?"
                            answer="Có. Chọn Đặt lịch, sau đó chọn dịch vụ, bác sĩ và khung giờ phù hợp trên trang đặt lịch."
                        />
                        <Faq
                            question="Tôi có cần đăng nhập để đặt lịch không?"
                            answer="Bạn có thể đặt lịch với tư cách khách. Trang đặt lịch sẽ hướng dẫn các bước xác nhận thông tin liên hệ."
                        />
                        <Faq
                            question="Xem chi phí dịch vụ ở đâu?"
                            answer="Giá hiện có được hiển thị trong danh sách và trang chi tiết dịch vụ. Bạn có thể trao đổi thêm khi đặt lịch."
                        />
                    </div>
                </Container>
            </section>
            <div className="fixed inset-x-0 bottom-0 z-40 border-t border-[#e4d6bf] bg-[#fbf8f3]/95 p-3 shadow-[0_-8px_24px_rgba(0,0,0,.08)] backdrop-blur-md lg:hidden">
                <ButtonLink
                    to="/booking"
                    className="w-full border-[#a9814f] bg-[#b99159] text-[#211d18] hover:bg-[#d0ab76]"
                >
                    <CalendarDays size={18} /> Đặt lịch tại JUNIE
                </ButtonLink>
            </div>
        </>
    );
}

function HomeServiceCard({ service }: { service: Service }) {
    return (
        <article className="flex h-full flex-col overflow-hidden rounded-xl border border-[#e6dac7] bg-white shadow-sm">
            <img
                src={service.image || treatment}
                alt={service.name}
                loading="lazy"
                width={1200}
                height={720}
                className="aspect-[16/10] w-full object-cover"
            />
            <div className="flex flex-1 flex-col p-6">
                <p className="text-xs font-semibold uppercase tracking-[.14em] text-[#a48156]">
                    {service.category || "Dịch vụ JUNIE"}
                </p>
                <h3 className="mt-3 text-2xl text-[#2e2c29]">{service.name}</h3>
                {service.description && (
                    <p className="mt-3 line-clamp-3 flex-1 text-sm leading-7 text-muted-foreground">
                        {service.description}
                    </p>
                )}
                <div className="mt-5 flex flex-wrap items-center justify-between gap-2 border-t border-[#eee6da] pt-5 text-sm">
                    <span className="flex items-center gap-1.5 text-muted-foreground">
                        <Clock3 size={15} /> {service.duration} phút
                    </span>
                    {Number(service.price) > 0 && (
                        <strong className="text-[#765739]">{money(service.price)}</strong>
                    )}
                </div>
                <div className="mt-5 flex flex-wrap gap-3">
                    <ButtonLink
                        to={`/booking?service_id=${service.id}`}
                        className="flex-1 border-[#b99159] bg-[#b99159] text-[#211d18] hover:bg-[#d0ab76]"
                    >
                        Đặt lịch
                    </ButtonLink>
                    <ButtonLink
                        to={servicePath(service)}
                        variant="outline"
                        className="flex-1 border-[#c7af8f]"
                    >
                        Chi tiết
                    </ButtonLink>
                </div>
            </div>
        </article>
    );
}

function HomeDoctorCard({ doctor }: { doctor: Doctor }) {
    return (
        <article className="rounded-xl border border-[#e4d7c4] bg-white p-5">
            {doctor.avatar ? (
                <img
                    src={doctor.avatar}
                    alt={doctor.name}
                    loading="lazy"
                    width={800}
                    height={880}
                    className="aspect-[5/4] w-full rounded-lg object-cover"
                />
            ) : (
                <div
                    className="grid aspect-[5/4] place-items-center rounded-lg bg-[#ece1d2] font-display text-5xl text-[#8c6b49]"
                    aria-hidden="true"
                >
                    {doctor.name.trim().charAt(0)}
                </div>
            )}
            {doctor.specialty && (
                <p className="mt-5 text-xs font-semibold uppercase tracking-[.12em] text-[#9e7c53]">
                    {doctor.specialty}
                </p>
            )}
            <h3 className="mt-2 text-2xl text-[#2e2c29]">{doctor.name}</h3>
            {doctor.bio && (
                <p className="mt-3 line-clamp-2 text-sm leading-6 text-muted-foreground">
                    {doctor.bio}
                </p>
            )}
            <ButtonLink
                to="/booking"
                variant="ghost"
                className="mt-4 px-0 text-[#765739] hover:bg-transparent"
            >
                Đặt lịch tư vấn <ArrowRight size={16} />
            </ButtonLink>
        </article>
    );
}

function Faq({ question, answer }: { question: string; answer: string }) {
    return (
        <details className="group py-5">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-base font-semibold text-[#302d29] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#b99159]">
                {question}
                <span className="text-2xl font-normal text-[#b99159] group-open:rotate-45">+</span>
            </summary>
            <p className="mt-3 max-w-2xl text-sm leading-7 text-muted-foreground">{answer}</p>
        </details>
    );
}
