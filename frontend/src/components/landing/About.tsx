import { BookOpenText, Stethoscope } from "lucide-react";
import { useFeaturedDoctor } from "@/hooks/useFeaturedDoctor";
import { DoctorPortrait } from "./DoctorPortrait";
import { LuxButton } from "./LuxButton";
import { SectionLabel } from "./SectionLabel";

export function About() {
    const { doctor, query } = useFeaturedDoctor();

    return (
        <section
            id="ve-junie"
            className="scroll-mt-20 bg-ivory px-6 py-14 md:px-10 md:py-16"
            aria-labelledby="junie-about-title"
        >
            <div className="mx-auto grid max-w-[1120px] items-center gap-x-14 gap-y-5 md:grid-cols-[minmax(0,0.78fr)_minmax(0,1.22fr)]">
                <header className="min-w-0 reveal md:col-start-2 md:row-start-1 md:self-end">
                    <SectionLabel label="Đội ngũ chuyên môn" />
                    <h2
                        id="junie-about-title"
                        className="mt-5 max-w-[660px] text-[clamp(2.6rem,5vw,4.2rem)] leading-[1.02] text-espresso"
                    >
                        Vẻ đẹp được chăm sóc bằng <em className="text-mocha">sự thấu hiểu</em> và
                        chuyên môn.
                    </h2>
                    <p className="mt-5 max-w-[570px] text-sm leading-7 text-muted-foreground md:text-[15px]">
                        Tại Junie, mỗi hành trình chăm sóc sắc đẹp bắt đầu bằng sự lắng nghe, đánh
                        giá chuyên môn và xây dựng giải pháp phù hợp với từng khách hàng.
                    </p>
                </header>

                <figure className="min-w-0 reveal md:col-start-1 md:row-span-2 md:row-start-1">
                    <DoctorPortrait
                        key={doctor?.avatar ?? doctor?.id ?? "pending"}
                        doctor={doctor}
                        className="mx-auto aspect-[4/3] w-full max-w-[380px] md:aspect-[4/5] md:max-h-[430px]"
                    />
                    <figcaption className="mx-auto mt-3 max-w-[380px] border-t border-border pt-3">
                        {doctor ? (
                            <>
                                <strong className="block break-words text-base font-medium text-espresso">
                                    {doctor.name}
                                </strong>
                                <span className="mt-0.5 block break-words text-sm text-mocha">
                                    {doctor.specialty}
                                </span>
                            </>
                        ) : (
                            <span className="text-sm text-muted-foreground">
                                {query.isPending
                                    ? "Đang tải hồ sơ bác sĩ..."
                                    : "Hồ sơ bác sĩ đang được cập nhật"}
                            </span>
                        )}
                    </figcaption>
                </figure>

                <div className="min-w-0 reveal md:col-start-2 md:row-start-2 md:self-start">
                    {query.isError ? (
                        <p
                            role="alert"
                            className="border-t border-border pt-4 text-sm text-muted-foreground"
                        >
                            Chưa thể tải hồ sơ bác sĩ.{" "}
                            <button
                                type="button"
                                onClick={() => void query.refetch()}
                                className="text-mocha underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha"
                            >
                                Thử lại
                            </button>
                        </p>
                    ) : doctor ? (
                        <dl className="border-y border-border">
                            <div className="flex gap-3 py-3.5">
                                <Stethoscope
                                    size={18}
                                    strokeWidth={1.3}
                                    className="mt-0.5 shrink-0 text-mocha"
                                    aria-hidden="true"
                                />
                                <div className="min-w-0">
                                    <dt className="text-xs uppercase tracking-[0.14em] text-mocha">
                                        Lĩnh vực chuyên môn
                                    </dt>
                                    <dd className="mt-1 break-words text-sm leading-6 text-espresso">
                                        {doctor.specialty}
                                    </dd>
                                </div>
                            </div>
                            <div className="flex gap-3 border-t border-border py-3.5">
                                <BookOpenText
                                    size={18}
                                    strokeWidth={1.3}
                                    className="mt-0.5 shrink-0 text-mocha"
                                    aria-hidden="true"
                                />
                                <div className="min-w-0">
                                    <dt className="text-xs uppercase tracking-[0.14em] text-mocha">
                                        Giới thiệu chuyên môn
                                    </dt>
                                    <dd className="mt-1 line-clamp-2 break-words text-sm leading-6 text-espresso">
                                        {doctor.bio ||
                                            "Thông tin chuyên môn đang được cập nhật sau khi xác minh."}
                                    </dd>
                                </div>
                            </div>
                        </dl>
                    ) : null}
                    <div className="mt-5 flex flex-wrap gap-2.5">
                        <LuxButton
                            href="/doi-ngu-bac-si"
                            variant="line"
                            className="min-h-11 border border-border px-5 py-3"
                        >
                            Xem hồ sơ bác sĩ
                        </LuxButton>
                        <LuxButton href="/booking" variant="solid" className="min-h-11 px-5 py-3">
                            Đặt lịch tư vấn
                        </LuxButton>
                    </div>
                </div>
            </div>
        </section>
    );
}
