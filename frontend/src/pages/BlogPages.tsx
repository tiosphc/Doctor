import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { Container, SectionHeading } from "@/components/common/Container";
import { BlogCard } from "@/components/cards/Cards";
import { ErrorState, EmptyState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { blogApi } from "@/services/blogApi";
import { errorMessage } from "@/services/api";
import treatment from "@/assets/treatment.jpg";

export function BlogsPage() {
    const [page, setPage] = useState(1);
    const query = useQuery({
        queryKey: ["blogs", { page }],
        queryFn: () => blogApi.list({ page, per_page: 9 }),
    });

    return (
        <main>
            <section className="section-space bg-muted">
                <Container>
                    <SectionHeading
                        eyebrow="Góc khoa học làn da"
                        title="Kiến thức & Tin tức"
                        description="Những chia sẻ dễ hiểu từ đội ngũ Junie để bạn chăm sóc làn da đúng cách mỗi ngày."
                    />
                    {query.isPending ? (
                        <LoadingState />
                    ) : query.isError ? (
                        <div className="mt-10">
                            <ErrorState
                                message={errorMessage(query.error)}
                                retry={() => query.refetch()}
                            />
                        </div>
                    ) : query.data.data.length === 0 ? (
                        <div className="mt-10">
                            <EmptyState message="Chưa có bài viết nào được đăng." />
                        </div>
                    ) : (
                        <>
                            <div className="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                                {query.data.data.map((blog) => (
                                    <BlogCard key={blog.id} blog={blog} />
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
            </section>
        </main>
    );
}

export function BlogDetailPage({ slug }: { slug: string }) {
    const query = useQuery({
        queryKey: ["blog", slug],
        queryFn: () => blogApi.find(slug),
        retry: false,
    });

    if (query.isPending) {
        return (
            <Container className="section-space">
                <LoadingState />
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

    const blog = query.data.data;
    const publishedDate = new Intl.DateTimeFormat("vi-VN", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    }).format(new Date(blog.published_at));

    return (
        <article className="section-space">
            <Container className="max-w-4xl">
                <Link
                    to="/blogs"
                    className="focus-premium inline-flex items-center gap-2 text-sm font-semibold text-primary"
                >
                    <ArrowLeft size={16} />
                    Tất cả bài viết
                </Link>
                <header className="mt-8">
                    <p className="label-luxury">
                        {blog.category} · {publishedDate}
                    </p>
                    <h1 className="mt-4 text-4xl leading-tight text-primary md:text-6xl">
                        {blog.title}
                    </h1>
                    <p className="mt-5 max-w-2xl text-lg leading-8 text-muted-foreground">
                        {blog.excerpt}
                    </p>
                    {blog.author && (
                        <p className="mt-5 text-sm text-muted-foreground">
                            Chia sẻ bởi{" "}
                            <span className="font-semibold text-primary">{blog.author.name}</span>
                        </p>
                    )}
                </header>
                <img
                    src={blog.image || treatment}
                    alt={blog.title}
                    className="mt-10 aspect-[16/8] w-full rounded-lg object-cover shadow-card"
                />
                <div className="mt-10 border-t pt-8 text-base leading-8 text-foreground/85">
                    <p className="whitespace-pre-line">{blog.content || blog.excerpt}</p>
                </div>
                <Link
                    to="/blogs"
                    className="focus-premium mt-10 inline-flex items-center gap-2 border-b border-primary pb-1 text-sm font-semibold text-primary"
                >
                    Xem thêm bài viết <ArrowRight size={16} />
                </Link>
            </Container>
        </article>
    );
}
