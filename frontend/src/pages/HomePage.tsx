import { useQuery } from "@tanstack/react-query";
import { Award, ShieldCheck, Phone, ArrowRight, ShoppingBag, Sparkles } from "lucide-react";
import hero from "@/assets/clinic-hero.jpg";
import treatment from "@/assets/treatment.jpg";
import { reviews, results } from "@/data/content";
import { productApi, productKeys } from "@/services/productApi";
import { doctorApi } from "@/services/doctorApi";
import { blogApi } from "@/services/blogApi";
import { Container, SectionHeading } from "@/components/common/Container";
import { ButtonLink } from "@/components/common/Button";
import { DoctorCard, CheckFeature, BlogCard } from "@/components/cards/Cards";
import { RetailProductCard } from "@/components/retail/RetailProductCard";

const homeProductFilters = { page: 1, sort: "newest" as const };

export function HomePage() {
    const productsQuery = useQuery({
        queryKey: productKeys.catalog(homeProductFilters),
        queryFn: () => productApi.catalog(homeProductFilters),
    });
    const doctorsQuery = useQuery({
        queryKey: ["doctors", { featured: true }],
        queryFn: () => doctorApi.list(),
    });
    const blogsQuery = useQuery({
        queryKey: ["blogs", { featured: true }],
        queryFn: () => blogApi.list({ per_page: 3 }),
    });
    const products = productsQuery.data?.data ?? [];
    const doctors = doctorsQuery.data?.data ?? [];
    return (
        <>
            <section className="relative isolate min-h-screen overflow-hidden bg-navy-deep text-white">
                <img
                    src={hero}
                    alt="Không gian phòng khám Junie"
                    className="absolute inset-0 -z-30 h-full w-full object-cover object-center"
                />

                <div className="absolute inset-0 -z-20 bg-black/20" />
                <div className="absolute inset-0 -z-10 bg-[linear-gradient(90deg,rgba(3,12,20,0.9)_0%,rgba(3,14,24,0.72)_48%,rgba(3,14,24,0.48)_100%)]" />
                <div className="absolute inset-x-0 bottom-0 -z-10 h-52 bg-gradient-to-t from-[#03111d]/70 to-transparent" />

                <div className="mx-auto flex min-h-screen w-full max-w-[1600px] flex-col justify-center gap-10 px-5 pb-12 pt-28 sm:px-8 lg:gap-14 lg:px-12 lg:pb-14 lg:pt-32 xl:px-16 2xl:px-20">
                    <div className="grid w-full items-center gap-10 lg:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)] xl:gap-16">
                        <div className="min-w-0 max-w-4xl">
                            <div className="inline-flex items-center rounded-full border border-[#c8a466]/35 bg-[#12344d]/70 px-4 py-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-[#efd29d] backdrop-blur-md sm:text-xs">
                                DERMATOLOGY • SKINCARE • PROFESSIONAL CARE
                            </div>

                            <h1 className="mt-6 font-display text-[2.5rem] font-semibold leading-[1.1] tracking-[-0.025em] text-white sm:text-5xl xl:text-[3.8rem]">
                                Chăm sóc làn da khoa học,
                                <br />
                                <span className="font-normal italic text-[#f0cc91]">
                                    bắt đầu từ sản phẩm phù hợp
                                </span>
                            </h1>

                            <p className="mt-6 max-w-2xl text-sm leading-7 text-white/85 sm:text-base lg:leading-8">
                                Khám phá các sản phẩm chăm sóc da được tuyển chọn theo nhu cầu, với
                                thông tin minh bạch, giá bán rõ ràng và hỗ trợ chuyên môn khi cần.
                            </p>

                            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                                <ButtonLink
                                    to="/products"
                                    className="w-full rounded-full border border-[#d3b06f]/40 bg-[#aa7d3f] px-6 text-white shadow-lg shadow-black/15 hover:bg-[#bd8d4b] sm:w-auto"
                                >
                                    <ShoppingBag size={16} />
                                    Mua sản phẩm
                                </ButtonLink>
                                <ButtonLink
                                    to="/dealer/apply"
                                    variant="outline"
                                    className="w-full rounded-full border-white/30 bg-[#0c2e47]/75 px-6 text-white backdrop-blur-sm hover:bg-[#123b58] hover:text-white sm:w-auto"
                                >
                                    Dành cho đại lý <ArrowRight size={16} />
                                </ButtonLink>
                            </div>
                            <ButtonLink
                                to="/booking"
                                variant="ghost"
                                className="mt-4 w-full justify-center px-0 text-xs font-medium text-white/85 hover:bg-transparent hover:text-white sm:w-auto sm:justify-start"
                            >
                                Cần tư vấn chuyên môn? Đặt lịch với bác sĩ <ArrowRight size={14} />
                            </ButtonLink>
                        </div>

                        <div className="relative mx-auto w-full max-w-[460px] lg:mx-0 lg:justify-self-end">
                            <div className="overflow-hidden rounded-xl border border-white/10 bg-[#15364b]/90 shadow-2xl shadow-black/30 backdrop-blur-xl">
                                <div className="flex items-start gap-3 border-b border-white/10 px-5 py-5">
                                    <div className="mt-0.5 grid h-8 w-8 place-items-center rounded-md bg-white/5 text-[#e9c989]">
                                        <Sparkles size={17} />
                                    </div>
                                    <div>
                                        <p className="text-xs font-semibold uppercase leading-4 tracking-[0.04em] text-white">
                                            CHĂM SÓC THEO NHU CẦU
                                        </p>
                                        <p className="mt-1 text-xs text-white/70">
                                            Tìm sản phẩm phù hợp với làn da
                                        </p>
                                    </div>
                                </div>

                                <div className="px-5 py-4">
                                    <div className="space-y-2.5">
                                        <HeroProductNeed label="Chăm sóc da mụn" />
                                        <HeroProductNeed label="Phục hồi & làm dịu" />
                                        <HeroProductNeed label="Dưỡng ẩm & hàng rào da" />
                                        <HeroProductNeed label="Chống nắng" />
                                        <HeroProductNeed label="Chăm sóc sắc tố" />
                                    </div>

                                    <div className="mt-5 border-t border-white/10 pt-4">
                                        <ButtonLink
                                            to="/products"
                                            variant="ghost"
                                            className="h-auto min-h-0 p-0 text-xs font-semibold text-[#e8c47f] hover:bg-transparent hover:text-[#f4d79f]"
                                        >
                                            Xem tất cả sản phẩm <ArrowRight size={14} />
                                        </ButtonLink>
                                    </div>
                                </div>
                            </div>

                            <div className="mt-4 flex items-center gap-4 rounded-xl border border-white/20 bg-white/95 px-5 py-4 text-[#0b2d49] shadow-xl shadow-black/15">
                                <div className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[#f3ead9] text-[#b78a49]">
                                    <ShieldCheck size={21} />
                                </div>
                                <div className="min-w-0">
                                    <strong className="text-xs sm:text-sm">
                                        Sản phẩm được tuyển chọn
                                    </strong>
                                    <p className="mt-0.5 text-[10px] leading-4 text-slate-500 sm:text-xs">
                                        Thông tin rõ ràng • Nguồn gốc minh bạch • Hỗ trợ tư vấn
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-5 border-t border-white/20 pt-6 sm:grid-cols-2 lg:grid-cols-4">
                        <HeroTrustItem
                            title="SẢN PHẨM CHÍNH HÃNG"
                            description="Nguồn gốc minh bạch"
                        />
                        <HeroTrustItem
                            title="GIÁ BÁN RÕ RÀNG"
                            description="Retail & giá dành cho đại lý"
                        />
                        <HeroTrustItem
                            title="ĐẶT HÀNG DỄ DÀNG"
                            description="Mua lẻ hoặc đặt số lượng lớn"
                        />
                        <HeroTrustItem
                            title="TƯ VẤN CHUYÊN MÔN"
                            description="Hỗ trợ khi khách hàng cần"
                        />
                    </div>
                </div>
            </section>
            <section className="section-space bg-card">
                <Container>
                    <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
                        <SectionHeading eyebrow="Junie Retail" title="Sản phẩm nổi bật" />
                        <p className="max-w-md text-sm leading-6 text-muted-foreground">
                            Khám phá sản phẩm chăm sóc da với thông tin và giá bán rõ ràng để lựa
                            chọn phù hợp với nhu cầu của bạn.
                        </p>
                    </div>
                    {productsQuery.isPending ? (
                        <p className="mt-10 text-sm text-muted-foreground">Đang tải sản phẩm...</p>
                    ) : productsQuery.isError ? (
                        <p className="mt-10 text-sm text-red-700">
                            Không thể tải sản phẩm lúc này.
                        </p>
                    ) : products.length === 0 ? (
                        <p className="mt-10 text-sm text-muted-foreground">
                            Chưa có sản phẩm đang bán.
                        </p>
                    ) : (
                        <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {products.slice(0, 3).map((product) => (
                                <RetailProductCard key={product.id} product={product} />
                            ))}
                        </div>
                    )}
                    <div className="mt-8 text-center">
                        <ButtonLink to="/products" variant="ghost">
                            Xem tất cả sản phẩm <ArrowRight size={16} />
                        </ButtonLink>
                    </div>
                </Container>
            </section>
            <section className="section-space">
                <Container>
                    <SectionHeading
                        eyebrow="Chuyên gia đồng hành"
                        title="Đội ngũ bác sĩ"
                        description="Chuyên môn vững vàng, thấu hiểu từng làn da và luôn đặt sự an toàn lên hàng đầu."
                    />
                    {doctorsQuery.isPending ? (
                        <p className="mt-10 text-sm text-muted-foreground">Đang tải bác sĩ...</p>
                    ) : doctorsQuery.isError ? (
                        <p className="mt-10 text-sm text-red-700">
                            Không thể tải danh sách bác sĩ lúc này.
                        </p>
                    ) : doctors.length === 0 ? (
                        <p className="mt-10 text-sm text-muted-foreground">
                            Chưa có bác sĩ đang hoạt động.
                        </p>
                    ) : (
                        <div className="mt-10 grid gap-x-8 gap-y-12 sm:grid-cols-2 xl:grid-cols-3">
                            {doctors.slice(0, 6).map((d) => (
                                <DoctorCard key={d.id} doctor={d} />
                            ))}
                        </div>
                    )}
                    <div className="mt-10 text-center">
                        <ButtonLink to="/doctors" variant="outline">
                            Xem thêm bác sĩ <ArrowRight size={16} />
                        </ButtonLink>
                    </div>
                </Container>
            </section>
            <section id="about" className="section-space scroll-mt-20 bg-muted">
                <Container>
                    <div className="grid gap-10 lg:grid-cols-[.8fr_1.2fr]">
                        <div>
                            <SectionHeading
                                eyebrow="Giá trị cốt lõi"
                                title="Tại sao hàng chục ngàn khách hàng tin chọn Junie"
                            />
                            <p className="mt-5 text-sm leading-6 text-muted-foreground">
                                Chúng tôi kết hợp nền tảng y khoa, công nghệ chính hãng và trải
                                nghiệm chăm sóc riêng tư.
                            </p>
                            <div className="mt-8 flex gap-3 rounded-md border bg-card p-5">
                                <Award className="shrink-0 text-secondary" />
                                <p className="text-sm leading-6">
                                    <strong>Cam kết hiệu quả bằng văn bản</strong>
                                    <br />
                                    <span className="text-muted-foreground">
                                        Mỗi liệu trình đều có mục tiêu và lộ trình theo dõi rõ ràng.
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <CheckFeature title="Bác sĩ chuyên môn cao">
                                100% bác sĩ có chứng chỉ hành nghề và kinh nghiệm chuyên sâu.
                            </CheckFeature>
                            <CheckFeature title="Công nghệ hiện đại">
                                Thiết bị chính hãng, được kiểm định và bảo trì định kỳ.
                            </CheckFeature>
                            <CheckFeature title="Quy trình chuẩn hóa">
                                Đánh giá, thực hiện và theo dõi theo tiêu chuẩn y khoa.
                            </CheckFeature>
                            <CheckFeature title="Chăm sóc cá nhân hóa">
                                Mỗi phác đồ dựa trên nền da, mong muốn và nhịp sống riêng.
                            </CheckFeature>
                        </div>
                    </div>
                </Container>
            </section>
            <section className="section-space bg-card">
                <Container>
                    <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
                        <SectionHeading eyebrow="Thay đổi chân thật" title="Kết quả khách hàng" />
                        <p className="max-w-md text-sm text-muted-foreground">
                            Hình ảnh ghi nhận sau liệu trình với sự đồng ý của khách hàng.
                        </p>
                    </div>
                    <div className="mt-10 grid gap-6 md:grid-cols-3">
                        {results.map((r, i) => (
                            <article key={r.title} className="overflow-hidden rounded-md border">
                                <div className="relative">
                                    <img
                                        src={treatment}
                                        alt={r.title}
                                        loading="lazy"
                                        className="aspect-[16/9] w-full object-cover"
                                    />
                                    <div className="absolute inset-x-0 bottom-0 grid grid-cols-2 bg-navy-deep/85 py-2 text-center text-xs text-primary-foreground">
                                        <span>Trước</span>
                                        <span>Sau {3 + i} tuần</span>
                                    </div>
                                </div>
                                <div className="p-5">
                                    <p className="text-xs text-secondary">{r.service}</p>
                                    <h3 className="mt-2 text-lg text-primary">{r.title}</h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {r.description}
                                    </p>
                                </div>
                            </article>
                        ))}
                    </div>
                </Container>
            </section>
            <section className="section-space">
                <Container>
                    <SectionHeading
                        center
                        eyebrow="Trải nghiệm tại Junie"
                        title="Khách hàng nói gì về chúng tôi"
                        description="Sự hài lòng và an tâm của khách hàng là minh chứng rõ nhất cho chất lượng y khoa."
                    />
                    <div className="scrollbar-none mt-10 flex snap-x gap-5 overflow-x-auto pb-4 md:grid md:grid-cols-3">
                        {reviews.map((r) => (
                            <article
                                key={r.name}
                                className="card-surface min-w-[85%] snap-center p-6 sm:min-w-[55%] md:min-w-0"
                            >
                                <p className="tracking-widest text-secondary">★★★★★</p>
                                <blockquote className="mt-5 font-display text-lg italic leading-7 text-primary">
                                    “{r.quote}”
                                </blockquote>
                                <p className="mt-6 text-sm font-semibold">{r.name}</p>
                                <p className="text-xs text-muted-foreground">{r.service}</p>
                            </article>
                        ))}
                    </div>
                </Container>
            </section>
            <section className="section-space bg-muted">
                <Container>
                    <SectionHeading eyebrow="Góc khoa học làn da" title="Kiến thức & Tin tức" />
                    {blogsQuery.isPending ? (
                        <p className="mt-10 text-sm text-muted-foreground">Đang tải bài viết...</p>
                    ) : blogsQuery.isError ? (
                        <p className="mt-10 text-sm text-red-700">
                            Không thể tải bài viết lúc này.
                        </p>
                    ) : blogsQuery.data?.data.length === 0 ? (
                        <p className="mt-10 text-sm text-muted-foreground">Chưa có bài viết nào.</p>
                    ) : (
                        <div className="mt-10 grid gap-6 md:grid-cols-3">
                            {blogsQuery.data?.data.map((blog) => (
                                <BlogCard key={blog.id} blog={blog} />
                            ))}
                        </div>
                    )}
                    <div className="mt-8 text-center">
                        <ButtonLink to="/blogs" variant="ghost">
                            Xem tất cả bài viết <ArrowRight size={16} />
                        </ButtonLink>
                    </div>
                </Container>
            </section>
            <section className="bg-navy-deep py-16 text-primary-foreground md:py-20">
                <Container>
                    <div className="grid items-center gap-10 lg:grid-cols-[1fr_.8fr]">
                        <div>
                            <p className="label-luxury">Tư vấn miễn phí cùng bác sĩ</p>
                            <h2 className="mt-4 max-w-2xl text-4xl md:text-5xl">
                                Bắt đầu hành trình chăm sóc làn da chuẩn khoa học ngay hôm nay
                            </h2>
                            <p className="mt-5 max-w-xl text-sm leading-6 opacity-75">
                                Đặt lịch trước để được bác sĩ chuyên khoa thăm khám và xây dựng lộ
                                trình riêng.
                            </p>
                            <p className="mt-6 flex items-center gap-2 font-semibold">
                                <Phone size={18} />
                                1900 6688
                            </p>
                        </div>
                        <div className="rounded-lg bg-card p-6 text-foreground md:p-8">
                            <h3 className="text-2xl text-primary">Đăng ký khám da chuyên sâu</h3>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Bác sĩ sẽ liên hệ xác nhận trong vòng 15 phút.
                            </p>
                            <ButtonLink to="/booking" className="mt-6 w-full">
                                Đặt lịch hẹn ngay
                            </ButtonLink>
                        </div>
                    </div>
                </Container>
            </section>
        </>
    );
}
function HeroTrustItem({ title, description }: { title: string; description: string }) {
    return (
        <div>
            <strong className="text-xs font-semibold tracking-[0.08em] text-[#f0cc91]">
                {title}
            </strong>
            <p className="mt-1 text-xs text-white/75">{description}</p>
        </div>
    );
}

function HeroProductNeed({ label }: { label: string }) {
    return (
        <ButtonLink
            to="/products"
            variant="ghost"
            className="group flex min-h-11 w-full items-center justify-between rounded-md border border-white/10 bg-white/[0.055] px-3.5 py-2.5 text-left text-xs font-medium text-white hover:bg-white/10 hover:text-white"
        >
            <span className="flex items-center gap-2.5">
                <Sparkles size={14} className="shrink-0 text-[#e7c37e]" />
                {label}
            </span>
            <ArrowRight
                size={13}
                className="shrink-0 text-white/50 transition-transform group-hover:translate-x-0.5"
            />
        </ButtonLink>
    );
}
