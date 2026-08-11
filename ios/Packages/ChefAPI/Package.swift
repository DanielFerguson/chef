// swift-tools-version: 6.0

import PackageDescription

let package = Package(
    name: "ChefAPI",
    platforms: [
        .iOS(.v18),
        .macOS(.v15),
    ],
    products: [
        .library(name: "ChefAPI", targets: ["ChefAPI"]),
    ],
    targets: [
        .target(name: "ChefAPI"),
        .testTarget(name: "ChefAPITests", dependencies: ["ChefAPI"]),
    ],
)
