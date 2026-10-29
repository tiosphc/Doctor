import "./SectionLabel.css";

export function SectionLabel({ label, light = false }: { label: string; light?: boolean }) {
    return (
        <div className={`junie-label eyebrow${light ? " junie-label--light" : ""}`}>
            <span className="junie-label__line" aria-hidden="true" />
            <span>{label}</span>
        </div>
    );
}
